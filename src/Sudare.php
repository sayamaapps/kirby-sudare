<?php

namespace Sudare;

/**
 * The facade a site talks to, and the one contract other packages read.
 * Written by the architect (SPEC.md §3.9), verified in tests/SudareTest.php
 * and tests/HttpTest.php.
 *
 *   require $base . '/vendor/autoload.php';
 *   Sudare\Sudare::guard($base);     // 401/503, or a file from media/ or assets/, then exit — or returns
 *   echo (new Kirby\Cms\App([...]))->render();
 *
 * Everything that decides is pure and testable without exit: handle() returns
 * a Response; guard() is handle() + emit() + exit. Only guard(), emit() and
 * seal() touch PHP's output and header functions.
 *
 * The headers of a response that passes are sent before Kirby runs, and
 * Kirby's own Response::send() replaces whatever header it sets by name — a
 * plugin route's `Cache-Control: public, max-age=…` would leave the preview
 * cacheable. So emit() also registers seal() with header_register_callback(),
 * which PHP runs right before it sends the headers: Cache-Control and
 * X-Robots-Tag are set again, Vary is merged. PHP keeps one such callback per
 * request: code that registers its own replaces the seal.
 *
 * The contract for other packages (Annai adds noindex when it is true):
 *
 *   class_exists(\Sudare\Sudare::class) && \Sudare\Sudare::active()
 *
 * active() is true once guard() (or handle()) ran in this request with a
 * valid, switched-on configuration. Before that — in a CLI, in a test, in a
 * site that never calls guard() — it is false; protects($base) answers the
 * same question without state.
 */
final class Sudare
{
    private static ?Config $config = null;

    /**
     * Protects the request or lets it through. Sends the decided headers;
     * when the decision is not "pass", also the body or file, then exits.
     *
     * @param string      $base   project root (the directory above site/ and public/)
     * @param string|null $public document root, default <base>/public
     * @param array|null  $server defaults to $_SERVER
     * @param array|null  $env    defaults to environment() — getenv() plus SUDARE_* keys of $server
     */
    public static function guard(string $base, ?string $public = null, ?array $server = null, ?array $env = null): void
    {
        $server ??= $_SERVER;
        $response = self::handle($base, $public, $server, $env ?? self::environment($server));
        self::emit($response);
        if (!$response->passes()) {
            exit;
        }
    }

    /**
     * The whole decision without side effects on the output: configuration,
     * request, Guard, then Media when Guard passes. HEAD and OPTIONS lose
     * their body here. Remembers the configuration for active()/config().
     */
    public static function handle(string $base, ?string $public, array $server, array $env): Response
    {
        $config = Config::load($base, $env);
        self::$config = $config;

        $request = Request::fromServer($server);
        $response = Guard::handle($config, $request);

        if ($response->passes()) {
            $file = Media::respond($public ?? rtrim($base, '/\\') . '/public', $request, $config);
            if ($file !== null) {
                $response = $file;
            }
        }

        if (!$request->wantsBody()) {
            $response = $response->withoutBody();
        }
        return $response;
    }

    /**
     * Sends a Response: headers always; status, body or file only when it is
     * not a pass (a pass leaves the status to Kirby). Never exits.
     *
     * A pass with headers also registers the seal (see the class comment):
     * Kirby has not run yet and may replace what is sent here. Sudare's own
     * answers (401, 503, 204, files) end the request and need no seal.
     */
    public static function emit(Response $response): void
    {
        if (!$response->passes()) {
            http_response_code($response->status());
        }
        $headers = $response->headers();
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if ($response->passes()) {
            if ($headers !== []) {
                header_register_callback(static fn () => self::seal($headers));
            }
            return;
        }
        $file = $response->filePath();
        if ($file !== null) {
            [$first, $last] = $response->range() ?? [0, (int) @filesize($file) - 1];
            self::stream($file, $first, $last - $first + 1);
        } elseif ($response->body() !== '') {
            echo $response->body();
        }
    }

    /**
     * Sets the protective headers again, right before PHP sends the response
     * (the callback emit() registers). Headers that Kirby or a plugin set in
     * the meantime are replaced; Vary is merged. No output, no exit, no
     * change of the status.
     *
     * @param array<string,string> $protective the headers of the pass response
     */
    public static function seal(array $protective): void
    {
        foreach (self::sealed($protective, headers_list()) as $name => $value) {
            header_remove($name);
            header($name . ': ' . $value, true);
        }
    }

    /**
     * What seal() sets again — pure. Every protective header with its own
     * value, which replaces whatever was sent under that name; except Vary,
     * which only ever grows: all values of all sent Vary lines first, then the
     * protective ones not yet present (case-insensitive, the first spelling
     * stays), or `*` when any of them is `*`. Headers that are not protective
     * are not in the result.
     *
     * @param array<string,string> $protective name => value of the pass response
     * @param list<string>         $sent       lines as headers_list() returns them ("Name: value")
     * @return array<string,string> name => value to set again
     */
    public static function sealed(array $protective, array $sent): array
    {
        $result = [];
        $vary = null;
        $varyName = null;
        foreach ($protective as $name => $value) {
            $name = (string) $name;
            if (strcasecmp($name, 'Vary') === 0) {
                $varyName ??= $name;
                $vary = array_merge($vary ?? [], self::tokens((string) $value));
                continue;
            }
            $result[$name] = (string) $value;
        }
        if ($varyName === null) {
            return $result;
        }

        $merged = [];
        foreach ($sent as $line) {
            $pos = strpos((string) $line, ':');
            if ($pos !== false && strcasecmp(trim(substr((string) $line, 0, $pos)), 'Vary') === 0) {
                $merged = array_merge($merged, self::tokens(substr((string) $line, $pos + 1)));
            }
        }
        $merged = array_merge($merged, $vary);

        $unique = [];
        foreach ($merged as $token) {
            if ($token === '*') {
                $unique = ['*'];
                break;
            }
            $unique[strtolower($token)] ??= $token;
        }
        $result[$varyName] = implode(', ', $unique);
        return $result;
    }

    /** @return list<string> the comma-separated values of a header, trimmed, empty ones dropped */
    private static function tokens(string $value): array
    {
        $tokens = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /**
     * Sends $length bytes of a file from byte $first on, in chunks. Output
     * buffers go first: with one active (php.ini's default output_buffering
     * is 4096) PHP would collect the whole file in memory, and a video beyond
     * memory_limit would end in a fatal error.
     */
    private static function stream(string $file, int $first, int $length): void
    {
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        fseek($handle, $first);
        while ($length > 0 && !feof($handle)) {
            $chunk = fread($handle, min(65536, $length));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
            $length -= strlen($chunk);
        }
        fclose($handle);
    }

    /** The contract: is this request behind the blind? False until guard()/handle() ran. */
    public static function active(): bool
    {
        return self::$config?->protects() ?? false;
    }

    /** The configuration guard()/handle() loaded in this request, or null. */
    public static function config(): ?Config
    {
        return self::$config;
    }

    /**
     * Stateless: would guard($base) protect? For code that runs before or
     * without guard(). Reads the environment of this process: in a CLI the
     * web server's SetEnv/env[] variables are not visible, so a configuration
     * that lives only there reads as `none` (README, "Calls").
     */
    public static function protects(string $base, ?array $env = null): bool
    {
        return Config::load($base, $env ?? self::environment())->protects();
    }

    /**
     * getenv() with the SUDARE_* keys of $server laid over it — Apache's
     * SetEnv lands in $_SERVER, not always in getenv() (FastCGI).
     *
     * @return array<string,string>
     */
    public static function environment(?array $server = null): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($server ?? $_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, Options::ENV_PREFIX)) {
                $env[$key] = $value;
            }
        }
        return $env;
    }

    /**
     * The package's strings as Kirby plugin translations (`sudare.<key>`),
     * for a site plugin that wants t('sudare.denied.heading'). Sudare runs
     * before Kirby and cannot register them itself.
     *
     * @return array<string, array<string,string>> language => [key => text]
     */
    public static function translations(): array
    {
        $translations = [];
        foreach (I18n::LANGUAGES as $language) {
            foreach (I18n::table($language) as $key => $text) {
                $translations[$language]['sudare.' . $key] = $text;
            }
        }
        return $translations;
    }

    /** Forgets the configuration of this process (tests). */
    public static function reset(): void
    {
        self::$config = null;
    }
}
