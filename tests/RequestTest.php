<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Request;
use Sudare\Response;

// SPEC.md §10.1: Request and Response.

$path = fn (string $uri): string => Request::fromServer(['REQUEST_URI' => $uri])->path();

// --- method ----------------------------------------------------------------
expect('GET', Request::fromServer([])->method(), 'method: missing is GET');
expect('GET', Request::fromServer(['REQUEST_METHOD' => 'get'])->method(), 'method: lower-case is upper-cased');
expect('GET', Request::fromServer(['REQUEST_METHOD' => 'FOO BAR'])->method(), 'method: garbage is GET');
expect('POST', Request::fromServer(['REQUEST_METHOD' => 'POST'])->method(), 'method: POST kept');

// --- paths -----------------------------------------------------------------
expect('/a/b', $path('/a/b?x=1#f'), 'path: query and fragment removed');
expect('/a/b', $path('//a///b/'), 'path: duplicate slashes collapsed, trailing slash removed');
expect('/a/c', $path('/a/./b/../c'), 'path: dot segments resolved');
expect('/etc/passwd', $path('/%2e%2e/etc/passwd'), 'path: encoded .. decoded once and resolved at root');
expect('/a%2Fb', $path('/a%252Fb'), 'path: decoded once, not twice');
expect('/ab', $path('/a%00b'), 'path: NUL removed');
expect('/', $path(''), 'path: empty is /');
expect('/panel', $path('/.well-known/acme-challenge/../../panel'), 'path: dot segments may climb out of ACME');
expect('/', $path('/'), 'path: root stays root');
expect('/a/b', Request::normalisePath('/a/b/'), 'normalisePath: trailing slash removed');

$raw = Request::fromServer(['REQUEST_URI' => '/a%2Fb/../c?x=1']);
expect('/a%2Fb/../c', $raw->rawPath(), 'rawPath: sent form without query');

// --- canonical: needs no resolving (exemptions depend on it) ----------------
$canonical = fn (string $uri): bool => Request::fromServer(['REQUEST_URI' => $uri])->isCanonical();
foreach (['/', '/api/hook', '/api/hook/', '/api/hook?x=../..', '/api/hook?x=%2F', '/.well-known/acme-challenge/Ab-_1'] as $uri) {
    holds($canonical($uri), "canonical: $uri");
}
foreach (['/panel/../api/hook', '/api/./hook', '/api//hook', '//api/hook', '/api/hook//', '/api%2Fhook', '/api%2fhook',
    '/x/%2e%2e/api/hook', '/api/hook%00', '/api%5Chook', '/api/hook/..', '/%61pi/hook', '/api/hoo%6B', '/caf%C3%A9', '/a%20b'] as $uri) {
    holds(!$canonical($uri), "not canonical: $uri");
}

// --- range: GET only, never with If-Range ------------------------------------
expect('bytes=0-1', Request::fromServer(['HTTP_RANGE' => 'bytes=0-1'])->range(), 'range: GET keeps the header');
expect(null, Request::fromServer(['REQUEST_METHOD' => 'HEAD', 'HTTP_RANGE' => 'bytes=0-1'])->range(), 'range: HEAD ignores it');
expect(null, Request::fromServer(['REQUEST_METHOD' => 'POST', 'HTTP_RANGE' => 'bytes=0-1'])->range(), 'range: POST ignores it');
expect('bytes=0-1', Request::fromServer(['HTTP_RANGE' => 'bytes=0-1', 'HTTP_IF_RANGE' => '"e"'])->range(), 'range: kept with If-Range (Media compares it)');
$conditional = Request::fromServer(['HTTP_IF_NONE_MATCH' => ' "a" ', 'HTTP_IF_MODIFIED_SINCE' => 'Tue, 14 Nov 2023 22:13:20 GMT', 'HTTP_IF_RANGE' => '"e"']);
expect(['"a"', 'Tue, 14 Nov 2023 22:13:20 GMT', '"e"', null], [$conditional->condition('If-None-Match'), $conditional->condition('If-Modified-Since'), $conditional->condition('If-Range'), Request::fromServer([])->condition('If-None-Match')], 'conditions: trimmed values, absent is null');
// routedMethod() must be Kirby's method exactly, including where Kirby is odd.
$kirbyCases = [
    'override lower-case'        => [['REQUEST_METHOD' => 'GET', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'post'], 'POST'],
    'override with blanks'       => [['REQUEST_METHOD' => 'GET', 'HTTP_X_HTTP_METHOD_OVERRIDE' => ' POST '], 'GET'],
    'override unknown'           => [['REQUEST_METHOD' => 'PUT', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'BOGUS'], 'PUT'],
    'method unknown'             => [['REQUEST_METHOD' => 'FOO'], 'GET'],
    'method lower-case'          => [['REQUEST_METHOD' => 'delete'], 'DELETE'],
    'no method'                  => [[], 'GET'],
];
foreach ($kirbyCases as $what => [$server, $expected]) {
    expect($expected, Request::fromServer($server)->routedMethod(), "routedMethod ($what)");
}
// The same cases through Kirby itself (vendor/getkirby/cms).
$kirbyAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($kirbyAutoload)) {
    require_once $kirbyAutoload;
    foreach ($kirbyCases as $what => [$server, $expected]) {
        $saved = $_SERVER;
        $_SERVER = $server;
        expect($expected, (new Kirby\Http\Request())->method(), "Kirby agrees ($what)");
        $_SERVER = $saved;
    }
}
expect('GET', Request::fromServer(['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST'])->method(), 'method: the transport method stays');
expect([true, true, false, false], [Request::fromServer([])->isRead(), Request::fromServer(['REQUEST_METHOD' => 'HEAD'])->isRead(), Request::fromServer(['REQUEST_METHOD' => 'OPTIONS'])->isRead(), Request::fromServer(['REQUEST_METHOD' => 'POST'])->isRead()], 'isRead: GET and HEAD only');
expect(null, Request::fromServer(['HTTP_RANGE' => ''])->range(), 'range: empty is null');
expect(null, Request::fromServer([])->range(), 'range: absent is null');

// --- credentials -----------------------------------------------------------
$basic = fn (string $value): array => ['HTTP_AUTHORIZATION' => $value];
expect(['user' => 'u', 'password' => 'p'], Request::fromServer(['PHP_AUTH_USER' => 'u', 'PHP_AUTH_PW' => 'p'])->credentials(), 'credentials: PHP_AUTH_USER/PW');
expect(['user' => 'u', 'password' => ''], Request::fromServer(['PHP_AUTH_USER' => 'u'])->credentials(), 'credentials: PHP_AUTH_USER without PW');
expect(['user' => 'u', 'password' => 'p'], Request::fromServer($basic('Basic ' . base64_encode('u:p')))->credentials(), 'credentials: HTTP_AUTHORIZATION');
expect(['user' => 'u', 'password' => 'p:q'], Request::fromServer($basic('Basic ' . base64_encode('u:p:q')))->credentials(), 'credentials: colon in password');
expect(['user' => 'u', 'password' => 'p'], Request::fromServer($basic('basic ' . base64_encode('u:p')))->credentials(), 'credentials: lower-case scheme accepted');
expect(null, Request::fromServer($basic('Bearer x'))->credentials(), 'credentials: Bearer is not Basic');
expect(null, Request::fromServer($basic('Basic !!!'))->credentials(), 'credentials: bad base64 is null');
expect(null, Request::fromServer($basic('Basic ' . base64_encode('nocolon')))->credentials(), 'credentials: no colon is null');
expect(['user' => 'r', 'password' => 'p'], Request::fromServer(['REDIRECT_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('r:p')])->credentials(), 'credentials: REDIRECT_HTTP_AUTHORIZATION fallback');
expect(['user' => 'php', 'password' => 'x'], Request::fromServer(['PHP_AUTH_USER' => 'php', 'PHP_AUTH_PW' => 'x', 'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('other:y')])->credentials(), 'credentials: PHP_AUTH_USER beats HTTP_AUTHORIZATION');
expect(null, Request::fromServer([])->credentials(), 'credentials: absent is null');

// --- token -----------------------------------------------------------------
expect('abc', Request::fromServer(['HTTP_X_SUDARE_TOKEN' => 'abc'])->token(), 'token: header value');
expect(null, Request::fromServer(['HTTP_X_SUDARE_TOKEN' => ''])->token(), 'token: empty is null');
expect(null, Request::fromServer([])->token(), 'token: absent is null');
expect('X-Sudare-Token', Request::TOKEN_HEADER, 'token: header name constant');

// --- verbs -----------------------------------------------------------------
$verbs = [
    'GET' => [false, false, true],
    'HEAD' => [true, false, false],
    'OPTIONS' => [false, true, false],
    'POST' => [false, false, true],
];
foreach ($verbs as $verb => [$head, $options, $body]) {
    $request = Request::fromServer(['REQUEST_METHOD' => $verb]);
    expect($head, $request->isHead(), "isHead: $verb");
    expect($options, $request->isOptions(), "isOptions: $verb");
    expect($body, $request->wantsBody(), "wantsBody: $verb");
}

// --- Response factories ----------------------------------------------------
$pass = Response::pass(['X-A' => '1']);
holds($pass->passes(), 'Response::pass passes');
expect(200, $pass->status(), 'Response::pass has status 200');
expect('1', $pass->header('x-a'), 'Response::pass keeps headers');

$text = Response::text(503, 'down');
expect(503, $text->status(), 'text: status');
expect('text/plain; charset=utf-8', $text->header('Content-Type'), 'text: content type');
expect('down', $text->body(), 'text: body');
holds(!$text->passes(), 'text does not pass');

$html = Response::html(401, '<p>x</p>');
expect('text/html; charset=utf-8', $html->header('Content-Type'), 'html: content type');

$empty = Response::empty(204, ['X-Robots-Tag' => 'noindex']);
expect(204, $empty->status(), 'empty: status');
expect('', $empty->body(), 'empty: no body');
expect(null, $empty->header('Content-Type'), 'empty: no content type');

$tmp = sudare_tmp() . '/file.css';
file_put_contents($tmp, 'body{}');
$file = Response::file($tmp, 'text/css');
expect($tmp, $file->filePath(), 'file: path');
expect('text/css', $file->header('Content-Type'), 'file: content type');
expect('6', $file->header('Content-Length'), 'file: Content-Length from filesize');
expect(null, Response::text(200, 'x')->filePath(), 'non-file response has no path');

$missing = Response::file($tmp . '.missing', 'text/css');
expect('0', $missing->header('Content-Length'), 'file: missing file has Content-Length 0');

// --- header access and immutability ----------------------------------------
$base = Response::text(200, 'x', ['X-Thing' => 'a']);
expect('a', $base->header('x-thing'), 'header: case-insensitive lookup');
expect(null, $base->header('X-Missing'), 'header: missing is null');

$changed = $base->withHeader('x-THING', 'b');
expect('b', $changed->header('X-Thing'), 'withHeader: replaces regardless of case');
expect(1, count(array_filter(array_keys($changed->headers()), fn ($k) => strtolower($k) === 'x-thing')), 'withHeader: keeps one entry');
expect('a', $base->header('X-Thing'), 'withHeader: original unchanged');

$many = $base->withHeaders(['A-One' => '1', 'B-Two' => '2']);
expect(['Content-Type', 'X-Thing', 'A-One', 'B-Two'], array_keys($many->headers()), 'withHeaders: order preserved, appended');

expect(401, $base->withStatus(401)->status(), 'withStatus: changes status');
expect(200, $base->status(), 'withStatus: original unchanged');

$headOnly = Response::file($tmp, 'text/css')->withoutBody();
expect('6', $headOnly->header('Content-Length'), 'withoutBody: keeps Content-Length');
expect('', $text->withoutBody()->body(), 'withoutBody: body empty');
expect('down', $text->body(), 'withoutBody: original body unchanged');

$shape = Response::text(401, 'b', ['X-A' => '1'])->toArray();
expect(['status', 'headers', 'body', 'file', 'range', 'pass'], array_keys($shape), 'toArray: keys');
expect([401, 'b', null, null, false], [$shape['status'], $shape['body'], $shape['file'], $shape['range'], $shape['pass']], 'toArray: values');

// --- withRange: 206 with the part's length -----------------------------------
$part = Response::file($tmp, 'text/css', ['Accept-Ranges' => 'bytes'])->withRange(1, 3, 6);
expect(206, $part->status(), 'withRange: 206');
expect([1, 3], $part->range(), 'withRange: first and last byte');
expect('bytes 1-3/6', $part->header('Content-Range'), 'withRange: Content-Range');
expect('3', $part->header('Content-Length'), 'withRange: Content-Length of the part');
expect(null, $part->withoutBody()->range(), 'withoutBody: no range either');
expect(null, Response::file($tmp, 'text/css')->range(), 'file: whole file by default');

// An accepted exempt entry has to be the path Kirby routes, below it too:
// Kirby drops "name:value" segments (URL parameters) from the routed path.
$entries = ['/lang:de/*', '/blog/tag:x/*', '/a;b/*', '/x:y', '/feed/*', '/api/webhook', '/a-b_c.d/*', '/v1.2/hook'];
foreach ($entries as $entry) {
    $normal = Sudare\Config::exemptEntry($entry);
    if ($normal === null) {
        continue;
    }
    $path = trim(str_replace('/*', '', $normal), '/');
    $routed = (new Kirby\Http\Uri('https://example.test/' . $path . '/panel'))->path()->toString();
    expect($path . '/panel', $routed, "Kirby routes below accepted entry '$entry' as written");
}
expect(null, Sudare\Config::exemptEntry('/lang:de/*'), 'entry with a Kirby URL parameter is refused');

finish('RequestTest');
