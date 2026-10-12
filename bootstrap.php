<?php

/**
 * Sudare (簾 — the bamboo blind: those inside see out, those outside cannot
 * see in; it is rolled up on launch day). Preview protection for Kirby sites
 * before launch: Basic Auth in front of Kirby, noindex on every response,
 * media/ and assets/ served through PHP so they are protected too.
 *
 * This is the package's entry point, the equivalent of a Kirby plugin's
 * index.php. Sudare is NOT a Kirby plugin: it runs BEFORE Kirby boots, called
 * from public/index.php, so there is no path around it — not the Panel, not
 * the API, not an error page (SPEC.md §1). Composer loads this file through
 * `autoload.files`; a site without Composer requires it directly.
 *
 * Shared file — owned by the architect (SPEC.md §2). Builders never edit it;
 * all logic lives in src/.
 *
 *   require $base . '/vendor/autoload.php';   // declares the classes, runs nothing
 *   Sudare\Sudare::guard($base);              // exits with 401/503 or serves a media/assets file
 *   echo (new Kirby\Cms\App([...]))->render(); // Kirby boots only here
 *
 * Off by default: without a configuration (site/config/sudare.php, the legacy
 * site/config/credentials.php, or SUDARE_* environment variables) guard()
 * does nothing and sends no header (SPEC.md §4).
 */

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Request.php';
require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Page.php';
require_once __DIR__ . '/src/Guard.php';
require_once __DIR__ . '/src/Media.php';
require_once __DIR__ . '/src/Cli.php';
require_once __DIR__ . '/src/Sudare.php';

if (!function_exists('sudare_option')) {
    /**
     * Reads `<key>` from the configuration guard() loaded in this request
     * (flat `sudare.<key>`, nested `['sudare' => [...]]` and bare spellings all
     * work, SPEC.md §4). Before guard() ran, or without a configuration, the
     * default is returned. Secrets (`hash`, `token`) are never returned: null.
     */
    function sudare_option(string $key, mixed $default = null): mixed
    {
        return Sudare\Sudare::config()?->option($key, $default) ?? $default;
    }
}
