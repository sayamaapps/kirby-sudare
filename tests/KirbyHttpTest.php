<?php

/**
 * Real HTTP with a real Kirby behind Sudare (SPEC.md §10.9). HttpTest.php
 * answers "Kirby" with a stand-in that sets the headers by hand; this file
 * lets Kirby\Cms\App render, so the headers on the wire are the ones
 * Kirby\Http\Response::send() really sets — the case the seal exists for: a
 * plugin route answers with `Cache-Control: public, max-age=…` after guard()
 * has sent `private, no-store`.
 *
 * Kirby is a dev dependency (`composer install`). Without vendor/ the file
 * prints one line and exits 0, so the package's tests still run on a plain
 * PHP without Composer.
 */

require_once __DIR__ . '/helpers.php';

$package = dirname(__DIR__);
$autoload = $package . '/vendor/autoload.php';
if (!is_file($autoload)) {
    echo "skipped: Kirby not installed\n";
    exit(0);
}
require_once $autoload;
if (!class_exists('Kirby\Cms\App')) {
    echo "skipped: Kirby not installed\n";
    exit(0);
}

$root = sudare_tmp();
$docroot = $root . '/public';
$router = $docroot . '/index.php';
$configFile = $root . '/site/config/sudare.php';

// A minimal Kirby: one page, one template, one route that stands in for a
// thumbnail route — it answers with a public cache header of its own.
sudare_write($root . '/content/site.txt', "Title: Muster\n");
sudare_write($root . '/content/home/home.txt', "Title: Home\n");
sudare_write($root . '/site/templates/default.php', "<h1><?= \$page->title() ?></h1>\n");
sudare_write($router, "<?php\n"
    . "\$base = dirname(__DIR__);\n"
    . "\$_SERVER['SCRIPT_NAME'] = '/index.php';\n"
    . "\$_SERVER['SCRIPT_FILENAME'] = __FILE__;\n"
    . "\$_SERVER['PHP_SELF'] = '/index.php';\n"
    . 'require ' . var_export($autoload, true) . ";\n"
    . "Sudare\\Sudare::guard(\$base);\n"
    . "echo (new Kirby\\Cms\\App([\n"
    . "    'roots' => [\n"
    . "        'index'   => __DIR__,\n"
    . "        'base'    => \$base,\n"
    . "        'content' => \$base . '/content',\n"
    . "        'site'    => \$base . '/site',\n"
    . "        'storage' => \$base . '/storage',\n"
    . "    ],\n"
    . "    'options' => [\n"
    . "        'debug'  => false,\n"
    . "        'routes' => [[\n"
    . "            'pattern' => 'thumb',\n"
    . "            'action'  => fn () => new Kirby\\Http\\Response('PNGBYTES', 'image/png', 200, [\n"
    . "                'Cache-Control'          => 'public, max-age=2592000',\n"
    . "                'X-Content-Type-Options' => 'nosniff',\n"
    . "            ]),\n"
    . "        ]],\n"
    . "    ],\n"
    . "]))->render();\n");

$password = 'test-password';
$auth = ['Authorization' => 'Basic ' . base64_encode('preview:' . $password)];
$token = ['X-Sudare-Token' => 'machine-token-123'];

$server = null;
$log = '';
try {
    $server = sudare_server($docroot, $router);

    /* No configuration: Sudare is off, Kirby's headers stand unchanged */
    $r = sudare_http($server['port'], 'GET', '/thumb');
    expect(200, $r['status'], 'no config, /thumb: status 200');
    expect('PNGBYTES', $r['body'], 'no config, /thumb: the route\'s body');
    expect('public, max-age=2592000', $r['headers']['cache-control'] ?? null, 'no config, /thumb: Kirby\'s cache-control stands');
    holds(!isset($r['headers']['x-robots-tag']), 'no config, /thumb: no x-robots-tag');
    $r = sudare_http($server['port'], 'GET', '/');
    expect(200, $r['status'], 'no config, /: status 200');
    holds(str_contains($r['body'], '<h1>Home</h1>'), 'no config, /: Kirby renders the home page');

    sudare_config($root, [
        'user'  => 'preview',
        'hash'  => sudare_hash($password),
        'token' => sudare_hash('machine-token-123'),
    ]);

    /* Protected, no credentials: Sudare answers, Kirby never runs */
    $r = sudare_http($server['port'], 'GET', '/thumb');
    expect(401, $r['status'], 'protected, anonymous /thumb: status 401');
    holds(!str_contains($r['body'], 'PNGBYTES'), 'protected, anonymous /thumb: the route\'s body is not sent');
    expect('private, no-store', $r['headers']['cache-control'] ?? null, 'protected, anonymous /thumb: cache-control');

    /* Protected, machine token: the route answers, the seal wins over its cache-control */
    $r = sudare_http($server['port'], 'GET', '/thumb', $token);
    expect(200, $r['status'], 'token /thumb: status 200');
    expect('PNGBYTES', $r['body'], 'token /thumb: the route\'s body');
    holds(str_starts_with($r['headers']['content-type'] ?? '', 'image/png'), 'token /thumb: content-type image/png');
    expect('private, no-store', $r['headers']['cache-control'] ?? null, 'token /thumb: cache-control sealed over public, max-age');
    $vary = array_map('trim', explode(',', $r['headers']['vary'] ?? ''));
    holds(in_array('Authorization', $vary, true) && in_array('X-Sudare-Token', $vary, true), 'token /thumb: vary names Authorization and X-Sudare-Token');
    expect('noindex, nofollow, noarchive', $r['headers']['x-robots-tag'] ?? null, 'token /thumb: x-robots-tag');
    expect('nosniff', $r['headers']['x-content-type-options'] ?? null, 'token /thumb: Kirby\'s own header survives the seal');

    /* A wrong token is no better than none */
    $r = sudare_http($server['port'], 'GET', '/thumb', ['X-Sudare-Token' => 'wrong-token']);
    expect(401, $r['status'], 'wrong token /thumb: status 401');
    holds(!str_contains($r['body'], 'PNGBYTES'), 'wrong token /thumb: the route\'s body is not sent');

    /* Protected, Basic Auth: a page Kirby renders */
    $r = sudare_http($server['port'], 'GET', '/', $auth);
    expect(200, $r['status'], 'Basic Auth /: status 200');
    holds(str_contains($r['body'], '<h1>Home</h1>'), 'Basic Auth /: Kirby renders the home page');
    expect('private, no-store', $r['headers']['cache-control'] ?? null, 'Basic Auth /: cache-control');
    expect('noindex, nofollow, noarchive', $r['headers']['x-robots-tag'] ?? null, 'Basic Auth /: x-robots-tag');
    $vary = array_map('trim', explode(',', $r['headers']['vary'] ?? ''));
    holds(in_array('Authorization', $vary, true) && in_array('X-Sudare-Token', $vary, true), 'Basic Auth /: vary names Authorization and X-Sudare-Token');

    /* The configuration goes away: the next request is open again, nothing is sealed */
    unlink($configFile);
    $r = sudare_http($server['port'], 'GET', '/thumb');
    expect(200, $r['status'], 'config removed, /thumb: status 200');
    expect('PNGBYTES', $r['body'], 'config removed, /thumb: the route\'s body');
    expect('public, max-age=2592000', $r['headers']['cache-control'] ?? null, 'config removed, /thumb: Kirby\'s cache-control stands');
    holds(!isset($r['headers']['x-robots-tag']), 'config removed, /thumb: no x-robots-tag');
} catch (Throwable $aborted) {
    expect(true, false, 'Kirby HTTP test aborted: ' . $aborted->getMessage());
} finally {
    if ($server !== null) {
        $log = sudare_server_stop($server);
    }
}

if ($GLOBALS['sudareFailures'] !== []) {
    fwrite(STDERR, "--- built-in server log ---\n" . $log . "\n--- end of log ---\n");
}

finish('Sudare HTTP with Kirby (built-in server)');
