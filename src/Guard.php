<?php

namespace Sudare;

/**
 * The decision of one request: pass, 503 while broken, pass for exempt
 * paths, 204 for OPTIONS, pass with a valid machine token or valid Basic
 * Auth credentials, otherwise 401 (SPEC.md §3.5, §6).
 *
 * Pure: no $_SERVER, no header(), no exit, no file system. The rows of the
 * decision table are evaluated in order, the first match wins (SPEC.md §6.2).
 */
final class Guard
{
    public const ROBOTS = 'noindex, nofollow, noarchive';

    /** A token-authenticated answer and an anonymous one are different variants for a shared cache. */
    public const VARY = 'Authorization, ' . Request::TOKEN_HEADER;

    public const RETRY_AFTER = '300';

    public const ALLOW = 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS';

    /** @return array<string,string> the headers every protected response carries (SPEC.md §6.1) */
    public static function headers(): array
    {
        return [
            'X-Robots-Tag'  => self::ROBOTS,
            'Cache-Control' => 'private, no-store',
            'Vary'          => self::VARY,
        ];
    }

    public static function handle(Config $config, Request $request): Response
    {
        // Row 1: nothing configured, or parked with active => false.
        if ($config->state() === Config::STATE_NONE || $config->state() === Config::STATE_OFF) {
            return Response::pass([]);
        }

        $path = $request->path();

        // Rows 2 and 4 only for a path that needs no resolving: Kirby routes
        // the raw path, so "/panel/../api/hook" must not count as "/api/hook".
        $canonical = $request->isCanonical();

        // Row 2: certificate renewal never depends on the configuration.
        // Sudare answers it itself (Media::respond), Kirby never sees it.
        if ($canonical && Config::startsWithSegment($path, Config::ACME)) {
            return Response::pass(self::headers());
        }

        I18n::setLanguage($config->language());

        // Row 3: a configuration that cannot be trusted fails closed.
        if ($config->state() === Config::STATE_BROKEN) {
            return Response::text(
                503,
                Page::unavailable($config),
                self::headers() + ['Retry-After' => self::RETRY_AFTER]
            );
        }

        // Row 4: exempt paths pass without credentials.
        if ($canonical && $config->isExempt($path, $request->routedMethod(), $request->sentMethod())) {
            return Response::pass(self::headers());
        }

        // Row 5: CORS preflight carries no credentials and reveals nothing.
        if ($request->isOptions()) {
            return Response::empty(204, self::headers() + ['Allow' => self::ALLOW]);
        }

        // Row 6: machine token from CI or an uptime check.
        $token = $request->token();
        if ($token !== null && $config->hasToken() && password_verify($token, (string) $config->token())) {
            return Response::pass(self::headers());
        }

        // Row 7: Basic Auth. Both checks always run, so a wrong user costs
        // the same time as a wrong password.
        $credentials = $request->credentials();
        if ($credentials !== null && $config->user() !== null) {
            $userOk = self::sameUser($config->user(), $credentials['user']);
            $passOk = password_verify($credentials['password'], (string) $config->hash());
            if ($userOk && $passOk) {
                return Response::pass(self::headers());
            }
        }

        // Row 8: everything else is refused.
        return Response::html(
            401,
            Page::denied($config),
            self::headers() + ['WWW-Authenticate' => Page::realmHeader($config->realm())]
        );
    }

    /** Constant-time comparison of two user names, whatever their lengths. */
    public static function sameUser(string $expected, string $given): bool
    {
        return hash_equals(hash('sha256', $expected), hash('sha256', $given));
    }
}
