<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Config;
use Sudare\Guard;
use Sudare\Page;
use Sudare\Request;
use Sudare\Response;

// SPEC.md §10.3: the decision table, pure Guard::handle().

/** Request from a URI, method and extra $_SERVER entries. */
function guardReq(string $uri, string $method = 'GET', array $server = []): Request
{
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method] + $server);
}

/** Every response a test produced, to check global properties (no Location header). */
$GLOBALS['guardResponses'] = [];
function guardHandle(Config $config, Request $request): Response
{
    $response = Guard::handle($config, $request);
    $GLOBALS['guardResponses'][] = $response;
    return $response;
}

$protective = Guard::headers();
$robots = ['X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, X-Sudare-Token'];

$on = Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'token' => sudare_hash('tok'), 'exempt' => ['/api/hook']]);
$legacy = Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'language' => 'de']);
$off = Config::fromArray(['active' => false, 'user' => 'test', 'hash' => sudare_hash()]);
$broken = Config::fromArray(['user' => 'x']);
$none = Config::none();

holds($broken->state() === Config::STATE_BROKEN, 'fixture: ["user" => "x"] is broken');
holds($on->state() === Config::STATE_ON, 'fixture: $on is on');
holds($off->state() === Config::STATE_OFF, 'fixture: $off is off');
holds($none->state() === Config::STATE_NONE, 'fixture: none');

$pass = static fn (array $headers) => ['status' => 200, 'headers' => $headers];

/* ---------------------------------------------------------------- headers */

expect(
    ['X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, X-Sudare-Token'],
    Guard::headers(),
    'headers: exact array'
);

/* ------------------------------------------------------- none and off */

$r = guardHandle($none, guardReq('/'));
holds($r->passes(), 'none, GET /: passes');
expect([], $r->headers(), 'none, GET /: no headers');

$r = guardHandle($off, guardReq('/panel'));
holds($r->passes(), 'off, GET /panel: passes');
expect([], $r->headers(), 'off, GET /panel: no headers');

/* ------------------------------------------------------------- broken */

$r = guardHandle($broken, guardReq('/'));
expect(503, $r->status(), 'broken, GET /: 503');
expect(Page::unavailable($broken), $r->body(), 'broken, GET /: body is Page::unavailable');
expect('text/plain; charset=utf-8', $r->header('Content-Type'), 'broken: text/plain');
expect('noindex, nofollow, noarchive', $r->header('X-Robots-Tag'), 'broken: X-Robots-Tag');
expect('private, no-store', $r->header('Cache-Control'), 'broken: Cache-Control');
expect('Authorization, X-Sudare-Token', $r->header('Vary'), 'broken: Vary names the token header too');
expect('300', $r->header('Retry-After'), 'broken: Retry-After 300');
holds(!str_contains($r->body(), '$2y$'), 'broken: body has no hash');
holds(!str_contains($r->body(), 'hash'), 'broken: body has no word hash');
holds(!str_contains($r->body(), '/'), 'broken: body has no path');

$r = guardHandle($broken, guardReq('/.well-known/acme-challenge/abc'));
holds($r->passes(), 'broken, ACME: passes');
expect($robots + [], only($r->headers(), array_keys($robots)), 'broken, ACME: three protective headers');
expect(null, $r->header('Retry-After'), 'broken, ACME: no Retry-After');

$r = guardHandle($broken, guardReq('/', 'HEAD'));
expect(503, $r->status(), 'broken, HEAD /: 503');

// Row 2 only for a path that needs no resolving: Kirby would route the raw one.
$r = guardHandle($broken, guardReq('/panel/../.well-known/acme-challenge/abc'));
expect(503, $r->status(), 'broken, ACME after dot segments: 503');

/* ------------------------------------------------------------ on: no credentials */

$r = guardHandle($on, guardReq('/'));
expect(401, $r->status(), 'on, GET / no credentials: 401');
expect('Basic realm="Preview", charset="UTF-8"', $r->header('WWW-Authenticate'), 'on, 401: WWW-Authenticate');
expect('text/html; charset=utf-8', $r->header('Content-Type'), 'on, 401: text/html');
expect(Page::denied($on), $r->body(), 'on, 401: body is Page::denied');
expect($robots, only($r->headers(), array_keys($robots)), 'on, 401: three protective headers');

$r = guardHandle($on, guardReq('/', 'GET', sudare_basic('test', 'wrong-password')));
expect(401, $r->status(), 'on, wrong password: 401');

$r = guardHandle($on, guardReq('/', 'GET', sudare_basic('Test', 'test-password')));
expect(401, $r->status(), 'on, right password wrong user (case): 401');

$r = guardHandle($on, guardReq('/', 'GET', sudare_basic('nobody', 'wrong-password')));
expect(401, $r->status(), 'on, wrong user and wrong password: 401');

$r = guardHandle($on, guardReq('/', 'GET', sudare_basic('test', '')));
expect(401, $r->status(), 'on, empty password: 401');

$r = guardHandle($on, guardReq('/', 'GET', ['HTTP_AUTHORIZATION' => 'Basic !!!']));
expect(401, $r->status(), 'on, Basic !!! malformed: 401');

/* ------------------------------------------------------------ on: right credentials */

$r = guardHandle($on, guardReq('/', 'GET', ['PHP_AUTH_USER' => 'test', 'PHP_AUTH_PW' => 'test-password']));
holds($r->passes(), 'on, right credentials via PHP_AUTH_*: passes');
expect($protective, $r->headers(), 'on, right credentials via PHP_AUTH_*: headers = Guard::headers() exactly');

$r = guardHandle($on, guardReq('/', 'GET', sudare_basic('test', 'test-password')));
holds($r->passes(), 'on, right credentials via HTTP_AUTHORIZATION: passes');
expect($protective, $r->headers(), 'on, HTTP_AUTHORIZATION pass: headers exact');

/* ------------------------------------------------------------ no path is special */

$paths = ['/panel', '/panel/site', '/api/system', '/media/pages/x/y.jpg', '/assets/css/x.css', '/robots.txt', '/sitemap.xml'];
foreach ($paths as $path) {
    $r = guardHandle($on, guardReq($path));
    expect(401, $r->status(), "on, $path without credentials: 401");
    $r = guardHandle($on, guardReq($path, 'GET', sudare_basic('test', 'test-password')));
    expect(200, $r->status(), "on, $path with credentials: pass");
    holds($r->passes(), "on, $path with credentials: passes");
}

/* ------------------------------------------------------------ exempt paths */

foreach (['/api/hook', '/api/hook/'] as $path) {
    $r = guardHandle($on, guardReq($path));
    holds($r->passes(), "on, exempt $path: passes without credentials");
    expect($protective, $r->headers(), "on, exempt $path: three headers");
}
// Without "/*" a sub-path is not exempt: it would reach Kirby's error page.
expect(401, guardHandle($on, guardReq('/api/hook/x'))->status(), 'on, /api/hook/x under an exact entry: 401');
$scoped = Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'exempt' => ['POST /pay/hook', '/feed/*', 'GET /health']]);
holds(guardHandle($scoped, guardReq('/pay/hook', 'POST'))->passes(), 'POST entry: POST passes');
expect(401, guardHandle($scoped, guardReq('/pay/hook'))->status(), 'POST entry: GET is 401 (no error page from Kirby)');
holds(guardHandle($scoped, guardReq('/feed/a/b'))->passes(), '/* entry: sub-path passes');
holds(guardHandle($scoped, guardReq('/feed'))->passes(), '/* entry: the path itself passes');
expect(401, guardHandle($scoped, guardReq('/feeds'))->status(), '/* entry: a sibling is 401');
holds(guardHandle($scoped, guardReq('/health', 'HEAD'))->passes(), 'GET entry: HEAD passes too');
expect(401, guardHandle($scoped, guardReq('/health', 'POST'))->status(), 'GET entry: POST is 401');
// Kirby routes X-HTTP-Method-Override: the exemption must see the same method.
expect(401, guardHandle($scoped, guardReq('/pay/hook', 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'get']))->status(), 'POST entry, POST overridden to GET: 401');
expect(401, guardHandle($scoped, guardReq('/health', 'GET', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE']))->status(), 'GET entry, GET overridden to DELETE: 401');
holds(guardHandle($scoped, guardReq('/pay/hook', 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'BOGUS']))->passes(), 'an override Kirby ignores changes nothing');
expect(401, guardHandle($scoped, guardReq('/pay/hook', 'GET', ['HTTP_X_HTTP_METHOD_OVERRIDE' => ' POST ']))->status(), 'GET with override " POST ": Kirby routes GET, 401');
// Strict: an entry with a method needs it as routed AND as sent.
expect(401, guardHandle($scoped, guardReq('/pay/hook', 'GET', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST']))->status(), 'GET overridden to POST: 401 (entry needs POST as sent too)');
holds(guardHandle($scoped, guardReq('/pay/hook', 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST']))->passes(), 'POST with override POST: passes');
expect(401, guardHandle($scoped, guardReq('/pay/hook', 'post'))->status(), 'lower-case "post" as sent: 401 (not the method of the entry)');
expect(401, guardHandle($scoped, guardReq('/health', 'PROPFIND'))->status(), 'GET entry: PROPFIND (Kirby routes GET) is 401');
// A HEAD entry needs a real HEAD; a GET overridden to HEAD gets the body.
$headOnly = Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'exempt' => ['HEAD /probe']]);
holds(guardHandle($headOnly, guardReq('/probe', 'HEAD'))->passes(), 'HEAD entry: a real HEAD passes');
expect(401, guardHandle($headOnly, guardReq('/probe', 'GET', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'HEAD']))->status(), 'HEAD entry: GET overridden to HEAD is 401');
// Only an exact "HEAD" loses its body; "head" is rendered in full.
foreach (['head', 'Head', 'hEAD', ' HEAD', 'HEAD '] as $sent) {
    expect(401, guardHandle($headOnly, guardReq('/probe', $sent))->status(), "HEAD entry: REQUEST_METHOD '$sent' is 401");
}
$r = guardHandle($on, guardReq('/api/hooks'));
expect(401, $r->status(), 'on, /api/hooks is not exempt: 401');

// Exempt only as sent: Kirby routes the raw path, where these are the Panel
// or another page.
foreach (['/panel/../api/hook', '/api//hook', '/api%2Fhook', '/x/%2e%2e/api/hook', '/%61pi/hook', '/%61pi/hook/x'] as $path) {
    $r = guardHandle($on, guardReq($path));
    expect(401, $r->status(), "on, $path (exempt only after resolving): 401");
    $r = guardHandle($on, guardReq($path, 'GET', sudare_basic('test', 'test-password')));
    holds($r->passes(), "on, $path with credentials: passes");
}

$r = guardHandle($on, guardReq('/.well-known/acme-challenge/x'));
holds($r->passes(), 'on, ACME without credentials: passes');
$r = guardHandle($on, guardReq('/panel/../.well-known/acme-challenge/x'));
expect(401, $r->status(), 'on, ACME after dot segments: 401');
$r = guardHandle($on, guardReq('/.well-known/other'));
expect(401, $r->status(), 'on, /.well-known/other: 401');

/* ------------------------------------------------------------ OPTIONS */

$r = guardHandle($on, guardReq('/', 'OPTIONS'));
expect(204, $r->status(), 'on, OPTIONS / no credentials: 204');
expect(Guard::ALLOW, $r->header('Allow'), 'on, OPTIONS: Allow header');
expect($robots, only($r->headers(), array_keys($robots)), 'on, OPTIONS: three headers');
expect('', $r->body(), 'on, OPTIONS: empty body');

/* ------------------------------------------------------------ token */

$r = guardHandle($on, guardReq('/', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'tok']));
holds($r->passes(), 'on, right token: passes');
expect($protective, $r->headers(), 'on, right token: headers exact');

$r = guardHandle($on, guardReq('/', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'wrong']));
expect(401, $r->status(), 'on, wrong token: 401');

$noToken = Config::fromArray(['user' => 'test', 'hash' => sudare_hash()]);
$r = guardHandle($noToken, guardReq('/', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'tok']));
expect(401, $r->status(), 'on, right token but config without token: 401');

/* ------------------------------------------------------------ legacy (de) */

$r = guardHandle($legacy, guardReq('/'));
expect(401, $r->status(), 'legacy, no credentials: 401');
expect('Basic realm="Vorschau", charset="UTF-8"', $r->header('WWW-Authenticate'), 'legacy: realm Vorschau');
holds(str_contains($r->body(), 'Diese Seite ist noch nicht öffentlich'), 'legacy: German heading');
holds(str_contains($r->body(), 'lang="de"'), 'legacy: lang="de"');

$r = guardHandle($legacy, guardReq('/', 'GET', sudare_basic('test', 'test-password')));
holds($r->passes(), 'legacy, right credentials: passes');

// The language is set per call: the English page is English again.
$r = guardHandle($on, guardReq('/'));
holds(str_contains($r->body(), 'lang="en"') && str_contains($r->body(), 'This site is not public yet'), 'after legacy call, English page is English again');

/* ------------------------------------------------------------ row order */

// Row 1 wins over row 2: a parked configuration sends nothing, not even on ACME.
$r = guardHandle($off, guardReq('/.well-known/acme-challenge/abc'));
holds($r->passes(), 'off, ACME: passes');
expect([], $r->headers(), 'off, ACME: no headers');

// Row 3 wins over row 5: a broken configuration answers OPTIONS with 503.
$r = guardHandle($broken, guardReq('/', 'OPTIONS'));
expect(503, $r->status(), 'broken, OPTIONS /: 503 (not 204)');

// Row 4 wins over row 5: exempt paths pass for OPTIONS too.
$r = guardHandle($on, guardReq('/api/hook', 'OPTIONS'));
holds($r->passes(), 'on, OPTIONS on exempt path: passes');
expect($protective, $r->headers(), 'on, OPTIONS on exempt path: three headers');

// Row 6 applies to every protected path, not only /.
$r = guardHandle($on, guardReq('/panel/site', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'tok']));
holds($r->passes(), 'on, right token on /panel/site: passes');

// A wrong token does not block valid Basic Auth credentials (row 7 still runs).
$r = guardHandle($on, guardReq('/', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'wrong'] + sudare_basic('test', 'test-password')));
holds($r->passes(), 'on, wrong token with right credentials: passes via row 7');

/* ------------------------------------------------------------ realm sanitising */

$weird = Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'realm' => 'He said "hi"\\back']);
$r = guardHandle($weird, guardReq('/'));
expect('Basic realm="He said hiback", charset="UTF-8"', $r->header('WWW-Authenticate'), 'realm with quotes and backslash sanitised');

/* ------------------------------------------------------------ sameUser */

holds(Guard::sameUser('test', 'test') === true, 'sameUser: equal → true');
holds(Guard::sameUser('test', 'testx') === false, 'sameUser: different lengths → false');
holds(Guard::sameUser('test', 'tes') === false, 'sameUser: shorter → false');
holds(Guard::sameUser('a', "a\0") === false, 'sameUser: a vs a+NUL → false');
holds(Guard::sameUser('test', 'Test') === false, 'sameUser: case-sensitive');

/* ------------------------------------------------------------ global properties */

$noLocation = true;
foreach ($GLOBALS['guardResponses'] as $response) {
    if ($response->header('Location') !== null) {
        $noLocation = false;
    }
}
holds($noLocation, 'no Location header in any response of this file');

finish('GuardTest');
