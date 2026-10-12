<?php

// Kirby entry point with Sudare in front of it. Copy this file to public/index.php.
// The web server points at public/: everything above it (site/, content/,
// vendor/) is outside the document root and not reachable from the internet.

$base = dirname(__DIR__);

// Built-in PHP server only (`php -S`, local development). The router script
// is called for every request, real files included. Apache never runs this
// block: public/.htaccess decides there instead.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;

    // Option A (this template): nothing is returned to the built-in server
    // for /assets/. Sudare serves public/assets/ itself, so locally the same
    // files sit behind the same password as on the server.
    //
    // Option B: return public assets directly to the built-in server. Only
    // for a site that does not want Sudare to serve /assets/ (`'assets' =>
    // false` in site/config/sudare.php does the same on the server; the
    // variable SUDARE_ASSETS alone, without user and hash, would be a broken
    // configuration and answer 503). Then the
    // protection does NOT apply to these files locally. Uncomment to use it:
    //
    // if (str_starts_with($path, '/assets/') && !preg_match('~(?:^|/)\.|\.php(?:/|$)~i', $path) && is_file($file)) {
    //     return false;
    // }

    // Some PHP versions set SCRIPT_NAME to the requested PDF or HTML path for
    // nested URLs with a file extension. Kirby would then take that directory
    // for its installation path and break redirects and assets. This front
    // controller always runs at the document root; REQUEST_URI keeps the real
    // target and query.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
    $_SERVER['PHP_SELF'] = '/index.php';

    // The same security headers as public/.htaccess, so a local run does not
    // use other rules than the Apache preview does. style-src 'self' also
    // blocks the inline <style> of Sudare's 401 page (it shows unstyled,
    // which is harmless); `vendor/bin/sudare-check` prints the 'sha256-…'
    // source to add to style-src, here and in .htaccess.
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Strict-Transport-Security: max-age=31536000');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

// Composer loads the package's bootstrap.php through autoload.files: the
// classes exist after this line, and nothing has run yet.
require $base . '/vendor/autoload.php';

// Preview protection BEFORE Kirby. Without a configuration (no
// site/config/sudare.php, no legacy credentials.php, no SUDARE_* variables)
// this does nothing and sends no header, so local development is not
// disturbed. With a configuration it answers 401 or 503 and exits, or serves
// a file from media/ or assets/ and exits, or returns and lets Kirby run.
// Not reached by the Panel, the API or an error page: nothing gets past it.
Sudare\Sudare::guard($base);

echo (new Kirby\Cms\App([
    'roots' => [
        'index'    => __DIR__,
        'base'     => $base,
        'content'  => $base . '/content',
        'site'     => $base . '/site',
        'storage'  => $base . '/storage',
    ],
]))->render();
