<?php

namespace Sudare;

/**
 * Serves files under public/media and public/assets through PHP, so the
 * site's .htaccess can route them to index.php and nothing is reachable by
 * guessing a URL while the blind is down (SPEC.md §3.7, §8).
 *
 * Sudare serves these files itself whether or not the site is protected;
 * only the cache headers differ. Pure apart from reading the file system:
 * no header(), no exit, no $_SERVER. Everything not on the allowlists returns
 * null and Kirby decides (usually a 404).
 *
 * Ported from site/config/vorschauschutz.php (vorschauMedien) of kirby-starter.
 */
final class Media
{
    /** Allowed everywhere under media/ (uploads included): images and PDF. */
    public const MEDIA_TYPES = [
        'webp' => 'image/webp', 'avif' => 'image/avif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
    ];

    /** Additionally allowed where only code lands: media/panel, media/plugins and assets/. */
    public const CODE_TYPES = [
        'js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json',
        'map' => 'application/json', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf',
        'otf' => 'font/otf', 'ico' => 'image/x-icon',
    ];

    /** Additionally allowed under assets/ only. */
    public const ASSET_TYPES = [
        'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
    ];

    /** Directories under media/ that Kirby fills from code, never from uploads. */
    public const CODE_DIRS = ['panel', 'plugins'];

    public const MAX_AGE = '31536000';

    /**
     * Resolves a normalised request path to a file Sudare may serve.
     *
     * @return array{file: string, type: string}|null null = not ours, let Kirby decide
     */
    public static function resolve(string $public, string $path, bool $media = true, bool $assets = true): ?array
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        if (str_starts_with($path, '/media/')) {
            if (!$media) {
                return null;
            }
            $folder = 'media';
        } elseif (str_starts_with($path, '/assets/')) {
            if (!$assets) {
                return null;
            }
            $folder = 'assets';
        } else {
            return null;
        }

        // Dotfiles and dot directories never leave, whatever their extension.
        foreach (explode('/', substr($path, 1)) as $segment) {
            if (str_starts_with($segment, '.')) {
                return null;
            }
        }

        // realpath() resolves "..", encoded traversal and symlinks; the
        // result must still lie inside the root, or it was an escape attempt.
        // A file PHP may not read is not ours either: fopen() would fail
        // after the headers and the client would cache an empty 200 under
        // the file's ETag.
        [$root, $file] = self::quietly(static fn (): array => [realpath($public . '/' . $folder), realpath($public . $path)]);
        if ($root === false || $file === false
            || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
            || !self::quietly(static fn (): bool => is_file($file) && is_readable($file))) {
            return null;
        }

        if ($folder === 'assets') {
            $types = self::MEDIA_TYPES + self::CODE_TYPES + self::ASSET_TYPES;
        } else {
            // Code types only for the resolved path inside panel/ or plugins/,
            // never for what the URL claimed. Uploads under media/pages/ stay
            // images and PDF: a served .js there would run on our own origin.
            $relative = substr($file, strlen($root) + 1);
            $parts = explode(DIRECTORY_SEPARATOR, $relative, 2);
            $inCodeDir = count($parts) === 2 && in_array($parts[0], self::CODE_DIRS, true);
            $types = $inCodeDir ? self::MEDIA_TYPES + self::CODE_TYPES : self::MEDIA_TYPES;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($types[$extension])) {
            return null;
        }

        return ['file' => $file, 'type' => $types[$extension]];
    }

    /** The file Response for this request, or null when Kirby should handle it. */
    public static function respond(string $public, Request $request, Config $config): ?Response
    {
        $acme = $config->enabled() && Config::startsWithSegment($request->path(), Config::ACME);

        // An ACME path never reaches Kirby: the file for GET and HEAD, else
        // an empty 404.
        if ($acme) {
            return $request->isRead() ? self::challenge($public, $request->path()) : Response::empty(404, Guard::headers());
        }
        // Files answer what Kirby routes as GET or HEAD, as its media routes
        // do: a GET overridden to POST is Kirby's (as a real POST is), and a
        // PROPFIND, which Kirby routes as GET, gets the file rather than
        // Kirby's error page with the site's navigation. OPTIONS and HEAD
        // still get no body (Request::wantsBody(), applied in Sudare::handle()).
        if (!in_array($request->routedMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $found = self::resolve($public, $request->path(), $config->media(), $config->assets());
        if ($found === null) {
            return null;
        }

        $size = (int) @filesize($found['file']);
        $mtime = (int) @filemtime($found['file']);
        $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
        $modified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        // Kirby's media/ URLs carry a hash that changes with the file, so a
        // year is safe. assets/ URLs do not: the browser asks again each time
        // and gets a 304 while the file is unchanged.
        $age = str_starts_with($request->path(), '/media/') ? 'max-age=' . self::MAX_AGE : 'no-cache';
        // While the blind is down, shared caches (company proxy, CDN) must not
        // keep the file; once the site is public, that is exactly what we want.
        $headers = $config->protects()
            ? [
                'Cache-Control' => 'private, ' . $age,
                'X-Robots-Tag'  => Guard::ROBOTS,
                'Vary'          => 'Authorization',
            ]
            : ['Cache-Control' => 'public, ' . $age];
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['ETag'] = $etag;
        $headers['Last-Modified'] = $modified;

        if (self::unchanged($request, $etag, $mtime)) {
            return Response::empty(304, $headers);
        }

        // Safari plays video and audio only from a server that answers ranges.
        $headers['Accept-Ranges'] = 'bytes';
        $response = Response::file($found['file'], $found['type'], $headers);

        // If-Range: the part only while the file is the one the client has.
        $ifRange = $request->condition('If-Range');
        $range = $ifRange === null || $ifRange === $etag || $ifRange === $modified
            ? self::range($request->range(), $size)
            : null;
        if ($range === false) {
            // An error for this one request: no cache may keep it for the URL.
            unset($headers['Accept-Ranges'], $headers['ETag'], $headers['Last-Modified']);
            $headers['Cache-Control'] = $config->protects() ? 'private, no-store' : 'no-store';
            return Response::empty(416, $headers + ['Content-Range' => 'bytes */' . $size]);
        }
        return $range === null ? $response : $response->withRange($range[0], $range[1], $size);
    }

    /**
     * Does the client have this version already (RFC 9110 §13.1.2, §13.1.3)?
     * If-None-Match decides when it is sent (weak comparison); otherwise
     * If-Modified-Since.
     */
    private static function unchanged(Request $request, string $etag, int $mtime): bool
    {
        $match = $request->condition('If-None-Match');
        if ($match !== null) {
            if (trim($match) === '*') {
                return true;
            }
            foreach (explode(',', $match) as $tag) {
                $tag = trim($tag);
                if (str_starts_with($tag, 'W/')) {
                    $tag = substr($tag, 2);
                }
                // mod_deflate/mod_brotli append "-gzip"/"-br" to the ETag of a
                // compressed answer; the browser sends that back.
                $tag = (string) preg_replace('/-(?:gzip|br)"$/', '"', $tag);
                if ($tag === $etag) {
                    return true;
                }
            }
            return false;
        }
        // Only an HTTP date counts; strtotime() would also take "now".
        $since = $request->condition('If-Modified-Since');
        $format = '!D, d M Y H:i:s \G\M\T';
        $date = $since === null ? false : \DateTimeImmutable::createFromFormat($format, $since, new \DateTimeZone('UTC'));
        // createFromFormat() rolls "99 Dec 9999 99:99:99" over; a real date reads back the same.
        return $date !== false && $date->format(substr($format, 1)) === $since && $mtime <= $date->getTimestamp();
    }

    /**
     * One byte range of a file of $size bytes (RFC 9110 §14.1.2): [first,
     * last], both inclusive. null sends the whole file: no header, several
     * ranges, an empty file or a header Sudare does not read. false when the
     * range starts beyond the file (416).
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function range(?string $header, int $size): array|false|null
    {
        if ($header === null || $size <= 0
            || preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m) !== 1
            || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            // "-500": the last 500 bytes.
            $length = self::number($m[2]);
            return $length === 0 ? false : [max(0, $size - $length), $size - 1];
        }
        $first = self::number($m[1]);
        if ($first >= $size) {
            return false;
        }
        if ($m[2] === '') {
            return [$first, $size - 1];
        }
        $last = self::number($m[2]);
        return $last < $first ? null : [$first, min($last, $size - 1)];
    }

    /** A byte position from digits; beyond PHP_INT_MAX it stays PHP_INT_MAX ((int) would wrap to 0). */
    private static function number(string $digits): int
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        return strlen($digits) > 18 ? PHP_INT_MAX : (int) $digits;
    }

    /**
     * The answer to an ACME challenge while Sudare is enabled: the token file
     * from public/.well-known/acme-challenge/ as plain text, or an empty 404.
     * Never Kirby: its error page would show the unlaunched site's navigation
     * to anyone. Usually the web server serves the file before PHP runs; this
     * is for servers that send every request to index.php.
     */
    private static function challenge(string $public, string $path): Response
    {
        $headers = Guard::headers() + ['X-Content-Type-Options' => 'nosniff'];
        $token = substr($path, strlen(Config::ACME) + 1);
        $file = self::quietly(static function () use ($public, $token): string|false {
            $root = realpath($public . Config::ACME);
            $file = $root !== false && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1
                ? realpath($root . '/' . $token)
                : false;
            return $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) && is_readable($file)
                ? $file
                : false;
        });
        if ($file === false) {
            return Response::empty(404, $headers);
        }
        return Response::file($file, 'text/plain', $headers);
    }

    /**
     * File-system checks under Sudare's own error handler: realpath() on a
     * symlink that points outside open_basedir warns, and a site's handler
     * set before guard() may throw on every warning (500) or let the warning
     * with both paths into the output (display_errors on). Same reason as
     * Config::load() (round 2j); found missing here in round 2l.
     */
    private static function quietly(callable $check): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $check();
        } finally {
            restore_error_handler();
        }
    }
}
