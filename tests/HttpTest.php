<?php

/**
 * Real HTTP through PHP's built-in server (SPEC.md §10.8): status lines and
 * headers as a browser sees them. The router is a two-line stand-in for
 * Kirby: it runs guard(), then prints "KIRBY active" or "KIRBY inactive".
 *
 * The configuration is read per request, so most scenarios rewrite the
 * config file between requests. Environment-only and legacy scenarios need
 * a server restart.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

$package = dirname(__DIR__);
$root = sudare_tmp();
$docroot = $root . '/public';
$router = $docroot . '/index.php';
$configFile = $root . '/site/config/sudare.php';
$legacyFile = $root . '/site/config/credentials.php';

sudare_write($router, "<?php\n"
    . "\$base = dirname(__DIR__);\n"
    . 'require ' . var_export($package . '/bootstrap.php', true) . ";\n"
    . "Sudare\\Sudare::guard(\$base);\n"
    . "header('X-Kirby: yes');\n"
    . "echo 'KIRBY ', Sudare\\Sudare::active() ? 'active' : 'inactive';\n");

$password = 'test-password';
$auth = ['Authorization' => 'Basic ' . base64_encode('preview:' . $password)];
$protective = [
    'x-robots-tag'  => 'noindex, nofollow, noarchive',
    'cache-control' => 'private, no-store',
    'vary'          => 'Authorization',
];
$unavailable = "The preview is temporarily unavailable.\n";

/** The media type of a Content-Type value; PHP appends "; charset=UTF-8" to text/* types by itself. */
$mediaType = static function (?string $contentType): string {
    return strtolower(trim(explode(';', (string) $contentType)[0]));
};

$server = null;
$log = '';
$responses = [];

$start = function (array $env = []) use (&$server, $docroot, $router): void {
    $server = sudare_server($docroot, $router, $env);
};
$stop = function () use (&$server, &$log): void {
    if ($server !== null) {
        $log .= sudare_server_stop($server);
        $server = null;
    }
};
$request = function (string $method, string $path, array $headers = []) use (&$server, &$responses): array {
    $response = sudare_http($server['port'], $method, $path, $headers);
    $responses[] = $response;
    return $response;
};
$protected = function (array $response) use ($protective): void {
    foreach ($protective as $name => $value) {
        expect($value, $response['headers'][$name] ?? null, "protective header $name");
    }
};

try {
    $start([]);
    /* 1. No configuration: the site is open, no protective headers */
    $r = $request('GET', '/');
    expect(200, $r['status'], 'no config: status 200');
    expect('KIRBY inactive', $r['body'], 'no config: reaches "Kirby", Sudare inactive');
    holds(!isset($r['headers']['x-robots-tag']) && !isset($r['headers']['vary']), 'no config: no X-Robots-Tag and no Vary');

    /* 2. Valid configuration, no credentials: 401 with every header */
    sudare_config($root, ['user' => 'preview', 'hash' => sudare_hash($password)]);
    $r = $request('GET', '/');
    expect(401, $r['status'], 'valid config, no credentials: status 401');
    expect('Basic realm="Preview", charset="UTF-8"', $r['headers']['www-authenticate'] ?? null, 'valid config: www-authenticate');
    $protected($r);
    holds(str_starts_with($r['headers']['content-type'] ?? '', 'text/html'), 'valid config: content-type text/html');
    holds(str_contains($r['body'], 'not public yet'), 'valid config: body is the 401 page');
    holds(!str_contains($r['body'], 'KIRBY'), 'valid config: Kirby not reached');

    /* 3. Credentials */
    $r = $request('GET', '/', $auth);
    expect(200, $r['status'], 'right credentials: status 200');
    expect('KIRBY active', $r['body'], 'right credentials: reaches "Kirby", Sudare active');
    expect('yes', $r['headers']['x-kirby'] ?? null, 'right credentials: the router header arrives');
    $protected($r);

    $r = $request('GET', '/', ['Authorization' => 'Basic ' . base64_encode('preview:nope')]);
    expect(401, $r['status'], 'wrong password: status 401');
    $r = $request('GET', '/', ['Authorization' => 'Basic ' . base64_encode('Preview:' . $password)]);
    expect(401, $r['status'], 'wrong user name (case): status 401');
    $r = $request('GET', '/', ['Authorization' => 'Basic !!!']);
    expect(401, $r['status'], 'malformed Basic header: status 401');

    /* 4. HEAD and OPTIONS */
    $r = $request('HEAD', '/');
    expect(401, $r['status'], 'HEAD without credentials: status 401');
    expect('', $r['body'], 'HEAD without credentials: no body');
    $protected($r);

    $r = $request('OPTIONS', '/');
    expect(204, $r['status'], 'OPTIONS without credentials: status 204');
    holds(isset($r['headers']['allow']), 'OPTIONS: Allow header present');
    expect('', $r['body'], 'OPTIONS: no body');
    $protected($r);

    /* 5. Panel and API are behind the same password */
    foreach (['/panel', '/api/system'] as $path) {
        $r = $request('GET', $path);
        expect(401, $r['status'], "$path without credentials: status 401");
        $r = $request('GET', $path, $auth);
        expect(200, $r['status'], "$path with credentials: status 200");
    }

    /* 6. Media and assets through PHP */
    sudare_write($root . '/public/media/pages/p/img.webp', 'RIFFTEST');
    sudare_write($root . '/public/media/pages/p/upload.js', 'alert(1)');
    sudare_write($root . '/public/assets/css/site.css', 'body{margin:0}');

    $r = $request('GET', '/media/pages/p/img.webp');
    expect(401, $r['status'], 'webp without credentials: status 401');
    holds(!str_contains($r['body'], 'RIFFTEST'), 'webp without credentials: the file bytes are not sent');

    $r = $request('GET', '/media/pages/p/img.webp', $auth);
    expect(200, $r['status'], 'webp with credentials: status 200');
    expect('image/webp', $mediaType($r['headers']['content-type'] ?? null), 'webp: content-type');
    expect('8', $r['headers']['content-length'] ?? null, 'webp: content-length');
    expect('private, max-age=31536000', $r['headers']['cache-control'] ?? null, 'webp: cache-control (protected variant)');
    expect('nosniff', $r['headers']['x-content-type-options'] ?? null, 'webp: x-content-type-options');
    expect('RIFFTEST', $r['body'], 'webp: body is the file');
    expect('noindex, nofollow, noarchive', $r['headers']['x-robots-tag'] ?? null, 'webp: x-robots-tag');

    $r = $request('HEAD', '/media/pages/p/img.webp', $auth);
    expect(200, $r['status'], 'webp HEAD with credentials: status 200');
    expect('8', $r['headers']['content-length'] ?? null, 'webp HEAD: content-length kept');
    expect('', $r['body'], 'webp HEAD: no body');

    $r = $request('GET', '/media/pages/p/upload.js', $auth);
    expect('KIRBY active', $r['body'], 'upload.js under media/pages is not served by Sudare: it reaches Kirby');

    $r = $request('GET', '/assets/css/site.css', $auth);
    expect(200, $r['status'], 'assets css with credentials: status 200');
    expect('text/css', $mediaType($r['headers']['content-type'] ?? null), 'assets css: content-type text/css');
    expect('body{margin:0}', $r['body'], 'assets css: body is the file');
    expect('bytes', $r['headers']['accept-ranges'] ?? null, 'assets css: accept-ranges bytes');
    expect('private, no-cache', $r['headers']['cache-control'] ?? null, 'assets css: revalidated, not kept for a year');
    $r = $request('GET', '/assets/css/site.css', $auth + ['If-None-Match' => (string) ($r['headers']['etag'] ?? '')]);
    expect([304, ''], [$r['status'], $r['body']], 'assets css again with its ETag: 304 without a body');

    /* 6a. Byte ranges: Safari plays video and audio only from a server that answers them */
    sudare_write($root . '/public/assets/video/clip.mp4', '0123456789');
    $r = $request('GET', '/assets/video/clip.mp4', $auth + ['Range' => 'bytes=0-1']);
    expect(206, $r['status'], 'range 0-1: status 206');
    expect('01', $r['body'], 'range 0-1: the first two bytes');
    expect('bytes 0-1/10', $r['headers']['content-range'] ?? null, 'range 0-1: content-range');
    expect('2', $r['headers']['content-length'] ?? null, 'range 0-1: content-length of the part');
    expect('video/mp4', $mediaType($r['headers']['content-type'] ?? null), 'range 0-1: content-type');
    expect('noindex, nofollow, noarchive', $r['headers']['x-robots-tag'] ?? null, 'range 0-1: x-robots-tag');
    $r = $request('GET', '/assets/video/clip.mp4', $auth + ['Range' => 'bytes=7-']);
    expect([206, '789', 'bytes 7-9/10'], [$r['status'], $r['body'], $r['headers']['content-range'] ?? null], 'range 7-: to the end');
    $r = $request('GET', '/assets/video/clip.mp4', $auth + ['Range' => 'bytes=-3']);
    expect([206, '789', 'bytes 7-9/10'], [$r['status'], $r['body'], $r['headers']['content-range'] ?? null], 'range -3: the last three bytes');
    $r = $request('GET', '/assets/video/clip.mp4', $auth + ['Range' => 'bytes=10-20']);
    expect([416, '', 'bytes */10'], [$r['status'], $r['body'], $r['headers']['content-range'] ?? null], 'range beyond the end: 416');
    $r = $request('GET', '/assets/video/clip.mp4', $auth + ['Range' => 'bytes=0-1', 'If-Range' => '"x"']);
    expect([200, '0123456789'], [$r['status'], $r['body']], 'range with If-Range: the whole file');
    $r = $request('GET', '/assets/video/clip.mp4', ['Range' => 'bytes=0-1']);
    expect(401, $r['status'], 'range without credentials: 401');
    holds(!str_contains($r['body'], '01'), 'range without credentials: no file bytes');

    /* 7. Machine token */
    sudare_config($root, [
        'user'   => 'preview',
        'hash'   => sudare_hash($password),
        'token'  => sudare_hash('machine-token-123'),
    ]);
    $r = $request('GET', '/', ['X-Sudare-Token' => 'machine-token-123']);
    expect(200, $r['status'], 'token header right: status 200');
    expect('KIRBY active', $r['body'], 'token header right: reaches "Kirby"');
    $r = $request('GET', '/', ['X-Sudare-Token' => 'wrong-token']);
    expect(401, $r['status'], 'token header wrong: status 401');

    /* 8. Exempt path, with the protective headers */
    sudare_config($root, [
        'user'   => 'preview',
        'hash'   => sudare_hash($password),
        'token'  => sudare_hash('machine-token-123'),
        'exempt' => ['/api/hook'],
    ]);
    $r = $request('GET', '/api/hook');
    expect(200, $r['status'], 'exempt path without credentials: status 200');
    expect('KIRBY active', $r['body'], 'exempt path: reaches "Kirby"');
    $protected($r);
    $r = $request('GET', '/api/hook/');
    expect(200, $r['status'], 'exempt path with a trailing slash: status 200');
    $r = $request('GET', '/api/hook/anything');
    expect(401, $r['status'], 'sub-path of an exact entry: 401, not Kirby\'s error page');
    sudare_config($root, ['user' => 'preview', 'hash' => sudare_hash($password), 'exempt' => ['POST /api/pay']]);
    expect(200, $request('POST', '/api/pay')['status'], 'POST entry: POST passes');
    expect(401, $request('POST', '/api/pay', ['X-HTTP-Method-Override' => 'GET'])['status'], 'POST entry with X-HTTP-Method-Override: GET → 401');

    // Kirby routes the raw path: a path that becomes "/api/hook" only after
    // decoding or resolving dot segments is the Panel or another page there.
    foreach (['/panel/../api/hook', '/panel/site/../../api/hook', '/api//hook', '/api/./hook', '/api%2Fhook', '/x/%2e%2e/api/hook', '/%61pi/hook', '/%61pi/hook/x'] as $path) {
        $r = $request('GET', $path);
        expect(401, $r['status'], "exempt only after resolving, $path: status 401");
        holds(!isset($r['headers']['x-kirby']), "exempt only after resolving, $path: Kirby not reached");
    }

    /* 9. ACME challenge: Sudare answers without credentials, Kirby never sees it */
    sudare_config($root, ['user' => 'preview', 'hash' => sudare_hash($password)]);
    sudare_write($root . '/public/.well-known/acme-challenge/Tok-en_1', 'TOKEN.THUMBPRINT');
    $r = $request('GET', '/.well-known/acme-challenge/Tok-en_1');
    expect(200, $r['status'], 'ACME challenge file without credentials: status 200');
    expect('TOKEN.THUMBPRINT', $r['body'], 'ACME challenge file: body is the file');
    expect('text/plain', $mediaType($r['headers']['content-type'] ?? null), 'ACME challenge file: text/plain');
    $protected($r);
    $r = $request('GET', '/.well-known/acme-challenge/missing');
    expect(404, $r['status'], 'ACME challenge without a file: status 404');
    expect('', $r['body'], 'ACME challenge without a file: empty body, not the site\'s error page');
    holds(!isset($r['headers']['x-kirby']), 'ACME challenge without a file: Kirby not reached');
    $protected($r);
    $r = $request('GET', '/panel/../.well-known/acme-challenge/Tok-en_1');
    expect(401, $r['status'], 'ACME only after resolving dot segments: status 401');

    /* 10. Broken configuration: 503 everywhere except the ACME path */
    sudare_write($configFile, '<?php return ["user" => "preview", "hash" => "plain-password"];');
    foreach (['/', '/panel', '/media/pages/p/img.webp'] as $path) {
        $r = $request('GET', $path, $auth);
        expect(503, $r['status'], "broken: $path is 503, even with credentials");
        expect('300', $r['headers']['retry-after'] ?? null, "broken: $path has retry-after 300");
        expect($unavailable, $r['body'], "broken: $path body is the one sentence");
        holds(!str_contains($r['body'], 'plain-password'), "broken: $path shows no secret");
    }
    $r = $request('GET', '/.well-known/acme-challenge/Tok-en_1');
    expect(200, $r['status'], 'broken: ACME challenge file still 200');
    expect('TOKEN.THUMBPRINT', $r['body'], 'broken: ACME challenge file served');
    $r = $request('GET', '/.well-known/acme-challenge/x');
    expect(404, $r['status'], 'broken: ACME challenge without a file is 404');
    holds(!isset($r['headers']['x-kirby']), 'broken: ACME without a file does not reach Kirby');

    /* 10a. A file that ends the script (exit) fails closed: 503, nothing of it leaks */
    sudare_write($configFile, '<?php echo "LEAK"; exit;');
    $r = $request('GET', '/', $auth);
    expect(503, $r['status'], 'exit in the file: status 503');
    expect($unavailable, $r['body'], 'exit in the file: body is the one sentence, no LEAK');
    expect('300', $r['headers']['retry-after'] ?? null, 'exit in the file: retry-after 300');
    $protected($r);

    /* 10b. A fatal error in the file is a 503 too. Not an exception (an undefined call is an
       Error and caught as `syntax`) but a real one: a function declared twice. With
       display_errors off, as on a server, PHP has set its own 500 status line by the time the
       shutdown function runs; the server here displays errors, so the file switches it off. */
    sudare_write($configFile, '<?php ini_set("display_errors", "0"); echo "LEAK"; function sudare_http_twice() {} eval("function sudare_http_twice() {}");');
    $r = $request('GET', '/', $auth);
    expect(503, $r['status'], 'fatal error in the file: status 503, not PHP\'s 500');
    expect($unavailable, $r['body'], 'fatal error in the file: body is the one sentence, no LEAK');
    expect('300', $r['headers']['retry-after'] ?? null, 'fatal error in the file: retry-after 300');
    $protected($r);

    /* 11. Environment only (restart with SUDARE_* in the server's environment) */
    $stop();
    unlink($configFile);
    $start(['SUDARE_USER' => 'preview', 'SUDARE_HASH' => sudare_hash($password)]);
    $r = $request('GET', '/');
    expect(401, $r['status'], 'env-only: no credentials, status 401');
    $r = $request('GET', '/', $auth);
    expect(200, $r['status'], 'env-only: right credentials, status 200');
    expect('KIRBY active', $r['body'], 'env-only: reaches "Kirby", Sudare active');
    $stop();

    /* 12. Legacy credentials.php: realm Vorschau, German body */
    $start([]);
    sudare_config($root, ['vorschau' => ['benutzer' => 'preview', 'hash' => sudare_hash('legacy-password')]], 'credentials.php');
    $r = $request('GET', '/');
    expect(401, $r['status'], 'legacy: no credentials, status 401');
    expect('Basic realm="Vorschau", charset="UTF-8"', $r['headers']['www-authenticate'] ?? null, 'legacy: realm Vorschau');
    holds(str_contains($r['body'], 'Diese Seite ist noch nicht öffentlich'), 'legacy: German body');
    holds(str_contains($r['body'], 'lang="de"'), 'legacy: lang="de"');
    $r = $request('GET', '/', ['Authorization' => 'Basic ' . base64_encode('preview:legacy-password')]);
    expect('KIRBY active', $r['body'], 'legacy: right credentials reach "Kirby"');
    $protected($r);
} catch (Throwable $aborted) {
    expect(true, false, 'HTTP test aborted: ' . $aborted->getMessage());
} finally {
    $stop();
}

/* 13. No response of this file redirects */
$withLocation = 0;
foreach ($responses as $response) {
    if (array_key_exists('location', $response['headers'])) {
        $withLocation++;
    }
}
expect(0, $withLocation, 'no Location header in any response');

if ($GLOBALS['sudareFailures'] !== []) {
    fwrite(STDERR, "--- built-in server log ---\n" . $log . "\n--- end of log ---\n");
}

finish('Sudare HTTP (built-in server)');
