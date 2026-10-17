<?php
// Media: files under public/media and public/assets served through PHP.
// Port of kirby-starter tests/PanelMedienTest.php (all 14 cases) plus the
// assets/ cases, traversal, dotfiles, symlinks and the header matrix of
// SPEC.md §8.3.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Config;
use Sudare\Media;
use Sudare\Request;
use Sudare\Response;
use Sudare\Sudare;

$root = sudare_tmp();
$public = $root . '/public';

$none = Config::none();
$on = Config::fromArray(['user' => 'test', 'hash' => sudare_hash()]);

/** Served through Media::respond() with the given config, or null. */
function serveUrl(string $url, Config $config): ?Response
{
    global $public;
    return Media::respond($public, Request::fromServer(['REQUEST_URI' => $url]), $config);
}

/**
 * Stores a file under public/ and checks that $url is served with $type
 * (and the realpath as file), or that it is not served at all ($type null).
 */
function check(string $stored, ?string $url, ?string $type, Config $config, string $what): void
{
    global $public;
    $url ??= '/' . $stored;
    if (!is_file($public . '/' . $stored)) {
        sudare_write($public . '/' . $stored, 'CONTENT ' . $stored);
    }
    $response = serveUrl($url, $config);
    if ($type === null) {
        holds($response === null, "$what: must not be served (got " . ($response ? $response->status() : 'null') . ')');
        return;
    }
    holds($response !== null, "$what: must be served");
    if ($response === null) {
        return;
    }
    expect(200, $response->status(), "$what: status 200");
    expect($type, $response->header('Content-Type'), "$what: Content-Type");
    expect(realpath($public . '/' . $stored), $response->filePath(), "$what: file is the realpath");
    expect((string) strlen('CONTENT ' . $stored), $response->header('Content-Length'), "$what: Content-Length");
}

/* ------------------------------------------------ panel and plugins (K1 cases) */

check('media/panel/abc/index.min.js', null, 'text/javascript', $none, 'panel js');
check('media/panel/abc/style.min.css', null, 'text/css', $none, 'panel css');
check('media/panel/abc/font.woff2', null, 'font/woff2', $none, 'panel woff2');
check('media/panel/abc/favicon.ico', null, 'image/x-icon', $none, 'panel ico');
check('media/plugins/index.js', null, 'text/javascript', $none, 'plugins js');
check('media/panel/abc/evil.php', null, null, $none, 'panel php');
check('media/panel/abc/skript.phtml', null, null, $none, 'panel phtml');
check('media/panel/abc/noext', null, null, $none, 'panel file without extension');
check('media/pages/p/upload.js', null, null, $none, 'uploaded js under pages');
check('media/pages/p/upload.json', null, null, $none, 'uploaded json under pages');
check('media/pages/p/upload.css', null, null, $none, 'uploaded css under pages');
check('media/pages/p/detour.js', '/media/panel/abc/../../pages/p/detour.js', null, $none, 'detour via panel/.. to pages');
check('media/pages/p/encoded.js', '/media/panel/abc/%2e%2e/%2e%2e/pages/p/encoded.js', null, $none, 'encoded detour to pages');
check('media/pages/p/image.webp', null, 'image/webp', $none, 'uploaded webp');

// The code-dir decision is made on the real path, not the URL: a link under
// panel/ that points at an upload stays an upload.
sudare_write($public . '/media/pages/p/linked.js', 'alert(1)');
@mkdir($public . '/media/panel/lnk', 0777, true);
symlink($public . '/media/pages/p/linked.js', $public . '/media/panel/lnk/link.js');
holds(serveUrl('/media/panel/lnk/link.js', $none) === null, 'symlink under panel/ to an uploaded js: not served');

// resolve() itself, without Request normalisation: the raw detour is refused too.
holds(Media::resolve($public, '/media/panel/abc/../../pages/p/detour.js') === null, 'resolve: raw ../ detour into pages refused');
holds(Media::resolve($public, '/media/panel/abc/%2e%2e/%2e%2e/pages/p/encoded.js') === null, 'resolve: raw encoded detour refused');

/* ------------------------------------------------------------- media uploads */

check('media/pages/p/doc.pdf', null, 'application/pdf', $none, 'pdf');
check('media/pages/p/vector.svg', null, 'image/svg+xml', $none, 'svg');
check('media/pages/p/modern.avif', null, 'image/avif', $none, 'avif');
check('media/pages/p/UPPER.JPG', null, 'image/jpeg', $none, 'upper-case extension JPG');
check('media/pages/p/clip.mp4', null, null, $none, 'video under media (Kirby serves it)');
check('media/panel/abc/FONT.WOFF2', null, 'font/woff2', $none, 'upper-case extension under panel');
check('media/pages/p/image.webp', '/media/pages/p/image.webp?v=2', 'image/webp', $none, 'query string stripped');

/* ------------------------------------------------------------------- assets */

check('assets/css/site.css', null, 'text/css', $none, 'assets css');
check('assets/js/app.mjs', null, 'text/javascript', $none, 'assets mjs');
check('assets/fonts/x.woff2', null, 'font/woff2', $none, 'assets woff2');
check('assets/img/logo.svg', null, 'image/svg+xml', $none, 'assets svg');
check('assets/site.webmanifest', null, 'application/manifest+json', $none, 'assets webmanifest');
check('assets/video/x.mp4', null, 'video/mp4', $none, 'assets mp4');
check('assets/secret.php', null, null, $none, 'assets php');
check('assets/.env', null, null, $none, 'assets dotfile');
check('assets/.hidden.css', null, null, $none, 'assets dotfile with allowed extension');
check('media/panel/abc/.hidden.js', null, null, $none, 'dotfile under panel');
sudare_write($public . '/assets/dir/inside.css', 'x');
holds(serveUrl('/assets/dir', $none) === null, 'assets: a directory is not served');
holds(serveUrl('/assets/dir/', $none) === null, 'assets: a directory with trailing slash is not served');

/* ------------------------------------------------------ traversal and outside */

sudare_config($root, ['user' => 'test', 'hash' => sudare_hash()]);
holds(serveUrl('/media/../site/config/sudare.php', $none) === null, 'traversal out of media to the configuration');
sudare_write($root . '/site/config/x.webp', 'outside');
holds(serveUrl('/media/../site/config/x.webp', $none) === null, 'traversal to a webp outside media');
holds(serveUrl('/media/%2e%2e/site/config/x.webp', $none) === null, 'encoded traversal to a webp outside media');
holds(serveUrl('/.htaccess', $none) === null, 'root dotfile is not ours');

// Round 2l: realpath() on a planted symlink that points outside open_basedir
// warns; a site's handler that throws on warnings must not make that a 500,
// and the warning (with both paths) must not reach the output. Root sees no
// open_basedir difference for permissions, but the warning is PHP's own, so
// this runs as root too.
if (function_exists('symlink')) {
    $jail = sudare_tmp();
    $outside = sudare_tmp();
    sudare_write($outside . '/x.png', 'PNG');
    mkdir($jail . '/public/media/pages/p', 0700, true);
    symlink($outside . '/x.png', $jail . '/public/media/pages/p/link.png');
    $process = proc_open(
        [PHP_BINARY, '-d', 'open_basedir=' . $jail . PATH_SEPARATOR . dirname(__DIR__), '-d', 'display_errors=1', '-r',
            'set_error_handler(function ($no, $message, $file, $line) { throw new ErrorException($message, 0, $no, $file, $line); });'
            . 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . 'echo var_export(Sudare\Media::resolve(' . var_export($jail . '/public', true) . ', "/media/pages/p/link.png"), true);'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    expect('NULL', $out, 'open_basedir + symlink outside + a handler that throws: null, no exception, no warning in the output');
} else {
    holds(true, 'open_basedir symlink: skipped (no symlink())');
}

// Round 2j: a file PHP may not read is not served either — fopen() would
// fail after the headers, and the client would cache an empty 200 under the
// file's ETag (and get 304 for it from then on).
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    sudare_write($public . '/assets/locked.css', 'CONTENT assets/locked.css');
    chmod($public . '/assets/locked.css', 0000);
    expect(null, Media::resolve($public, '/assets/locked.css'), 'unreadable file: not ours');
    expect(null, serveUrl('/assets/locked.css', $none), 'unreadable file: no response, Kirby decides');
    chmod($public . '/assets/locked.css', 0600);
    check('assets/locked.css', null, 'text/css', $none, 'the same file, readable again');
} else {
    holds(true, 'unreadable file: skipped (running as root)');
}
holds(serveUrl('/other/x.png', $none) === null, 'path outside media and assets is null');
holds(serveUrl('/mediax/pages/p/image.webp', $none) === null, 'prefix lookalike /mediax is null');

// Symlink inside media pointing out of the root.
sudare_write($root . '/site/config/secret.png', 'secret');
symlink($root . '/site/config/secret.png', $public . '/media/pages/p/link.png');
holds(serveUrl('/media/pages/p/link.png', $none) === null, 'symlink out of media is not served');
holds(Media::resolve($public, '/media/pages/p/link.png') === null, 'resolve: symlink out of media is null');

/* ----------------------------------------------------------- media/assets flags */

holds(Media::resolve($public, '/media/pages/p/image.webp', false, true) === null, 'resolve: media=false refuses /media');
holds(Media::resolve($public, '/assets/css/site.css', true, false) === null, 'resolve: assets=false refuses /assets');
holds(Media::resolve($public, '/assets/css/site.css', false, true) !== null, 'resolve: media=false leaves /assets alone');
holds(Media::resolve($public, '/media/pages/p/image.webp', true, false) !== null, 'resolve: assets=false leaves /media alone');
holds(serveUrl('/media/pages/p/image.webp', Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'media' => false])) === null, 'respond: media=false from configuration');
holds(serveUrl('/assets/css/site.css', Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'assets' => false])) === null, 'respond: assets=false from configuration');

/* ------------------------------------------------------------- resolve() shape */

$found = Media::resolve($public, '/media/pages/p/image.webp');
expect(['file' => realpath($public . '/media/pages/p/image.webp'), 'type' => 'image/webp'], $found, 'resolve returns file and type');
holds(Media::resolve($public, '/media/pages/p/missing.webp') === null, 'resolve: missing file is null');

/* ------------------------------------------------------------- header matrix */

$protected = serveUrl('/media/pages/p/image.webp', $on);
holds($protected !== null, 'protected: webp served');
if ($protected !== null) {
    expect('private, max-age=31536000', $protected->header('Cache-Control'), 'protected: Cache-Control private');
    expect('noindex, nofollow, noarchive', $protected->header('X-Robots-Tag'), 'protected: X-Robots-Tag');
    expect('Authorization, X-Sudare-Token', $protected->header('Vary'), 'protected: Vary names Authorization and the token header');
    expect('nosniff', $protected->header('X-Content-Type-Options'), 'protected: nosniff');
    expect('image/webp', $protected->header('Content-Type'), 'protected: Content-Type');
    expect((string) strlen('CONTENT media/pages/p/image.webp'), $protected->header('Content-Length'), 'protected: Content-Length is the byte count');
}

$open = serveUrl('/media/pages/p/image.webp', $none);
holds($open !== null, 'none: webp served');
if ($open !== null) {
    expect('public, max-age=31536000', $open->header('Cache-Control'), 'none: Cache-Control public');
    expect(null, $open->header('X-Robots-Tag'), 'none: no X-Robots-Tag');
    expect(null, $open->header('Vary'), 'none: no Vary');
    expect('nosniff', $open->header('X-Content-Type-Options'), 'none: nosniff');
}

$openOff = serveUrl('/media/pages/p/image.webp', Config::fromArray(['active' => false]));
holds($openOff !== null && $openOff->header('Cache-Control') === 'public, max-age=31536000', 'off: served publicly like none');

/* ---------------------------------------------------------------- byte ranges */

expect(null, Media::range(null, 10), 'range: no header, whole file');
expect([0, 1], Media::range('bytes=0-1', 10), 'range: 0-1');
expect([0, 1], Media::range(' Bytes = 0 - 1 ', 10), 'range: unit case and blanks');
expect([7, 9], Media::range('bytes=7-', 10), 'range: open end');
expect([7, 9], Media::range('bytes=-3', 10), 'range: suffix');
expect([0, 9], Media::range('bytes=-30', 10), 'range: suffix longer than the file is the whole file');
expect([5, 9], Media::range('bytes=5-99', 10), 'range: end beyond the file is cut');
expect([9, 9], Media::range('bytes=9-9', 10), 'range: last byte');
expect(false, Media::range('bytes=10-', 10), 'range: start at the size is 416');
expect(false, Media::range('bytes=-0', 10), 'range: empty suffix is 416');
expect(null, Media::range('bytes=5-2', 10), 'range: end before start is ignored');
expect(null, Media::range('bytes=0-1,4-5', 10), 'range: several ranges, whole file');
expect(null, Media::range('items=0-1', 10), 'range: other unit ignored');
expect(null, Media::range('bytes=-', 10), 'range: no numbers ignored');
expect(null, Media::range('bytes=0-1', 0), 'range: empty file, whole file');
$huge = str_repeat('9', 400);
expect(false, Media::range("bytes=$huge-", 10), 'range: a start of 400 digits is beyond the file (416), not 0');
expect([3, 9], Media::range("bytes=3-$huge", 10), 'range: an end of 400 digits is cut to the file');
expect([0, 9], Media::range("bytes=-$huge", 10), 'range: a suffix of 400 digits is the whole file');
expect([2, 4], Media::range('bytes=0002-0004', 10), 'range: leading zeros');

sudare_write($public . '/assets/clip.mp4', '0123456789');
$rangeOf = static fn (string $range, Config $config): ?Response => Media::respond(
    $public,
    Request::fromServer(['REQUEST_URI' => '/assets/clip.mp4', 'HTTP_RANGE' => $range]),
    $config
);
$part = $rangeOf('bytes=2-4', $on);
expect([206, [2, 4], 'bytes 2-4/10', '3', 'bytes'], [$part?->status(), $part?->range(), $part?->header('Content-Range'), $part?->header('Content-Length'), $part?->header('Accept-Ranges')], 'respond: 206 with the part');
expect('private, no-cache', $part?->header('Cache-Control'), 'respond 206: cache header as for the whole file');
$beyond = $rangeOf('bytes=10-', $none);
expect([416, 'bytes */10', null, null], [$beyond?->status(), $beyond?->header('Content-Range'), $beyond?->filePath(), $beyond?->header('Accept-Ranges')], 'respond: 416 without a file');
expect(['no-store', null], [$beyond?->header('Cache-Control'), $beyond?->header('ETag')], 'respond 416: no cache keeps it for the URL');
expect('private, no-store', $rangeOf('bytes=10-', $on)?->header('Cache-Control'), 'respond 416 protected: private, no-store');
expect('bytes', serveUrl('/assets/clip.mp4', $on)?->header('Accept-Ranges'), 'respond: whole file announces ranges');

/* ------------------------------------------- validators, 304 and the cache length */

$withServer = static fn (string $url, array $server, Config $config): ?Response => Media::respond(
    $public,
    Request::fromServer(['REQUEST_URI' => $url] + $server),
    $config
);
// Round 2e: a GET overridden to POST is what Kirby routes as POST; its media
// routes take GET only, so the file is not Sudare's to serve (a "POST /assets/*"
// entry would otherwise hand out the whole file without a login).
expect(null, $withServer('/assets/clip.mp4', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST'], $none), 'respond: GET overridden to POST: no file, Kirby routes it');
holds($withServer('/assets/clip.mp4', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'HEAD'], $none) !== null, 'respond: GET overridden to HEAD is still a read');
// Round 2g: Kirby routes PROPFIND as GET; the file, not Kirby's error page.
holds($withServer('/assets/clip.mp4', ['REQUEST_METHOD' => 'PROPFIND'], $none)?->filePath() !== null, 'respond: PROPFIND (routed GET) gets the file');
sudare_write($public . '/assets/app.js', 'console.log(1)');
touch($public . '/assets/app.js', 1700000000);
$etag = '"' . dechex(strlen('console.log(1)')) . '-' . dechex(1700000000) . '"';
$lastModified = 'Tue, 14 Nov 2023 22:13:20 GMT';
$js = serveUrl('/assets/app.js', $none);
// Open site: no Cache-Control from Sudare at all — the server's rules apply, as
// if the rewrite were not there. `public, no-cache` (until 1.0.0) made every
// page view of a live site re-request each asset.
expect([null, $etag, $lastModified], [$js?->header('Cache-Control'), $js?->header('ETag'), $js?->header('Last-Modified')], 'assets open: no Cache-Control, ETag and Last-Modified stay');
expect([null, 'nosniff'], [serveUrl('/assets/app.js', Config::fromArray(['active' => false]))?->header('Cache-Control'), serveUrl('/assets/app.js', Config::fromArray(['active' => false]))?->header('X-Content-Type-Options')], 'assets off: no Cache-Control either, nosniff stays');
expect(null, $withServer('/assets/app.js', ['HTTP_IF_NONE_MATCH' => $etag], $none)?->header('Cache-Control'), 'assets open 304: no Cache-Control either');
expect('private, no-cache', serveUrl('/assets/app.js', $on)?->header('Cache-Control'), 'assets protected: private, no-cache');
expect('public, max-age=31536000', serveUrl('/media/pages/p/image.webp', $none)?->header('Cache-Control'), 'media: a year (Kirby hashes the URLs)');
holds(serveUrl('/media/pages/p/image.webp', $none)?->header('ETag') !== null, 'media: ETag too');

foreach ([
    'If-None-Match equal'            => [['HTTP_IF_NONE_MATCH' => $etag], 304],
    'If-None-Match weak'             => [['HTTP_IF_NONE_MATCH' => 'W/' . $etag], 304],
    'If-None-Match in a list'        => [['HTTP_IF_NONE_MATCH' => '"x", ' . $etag], 304],
    'If-None-Match *'                => [['HTTP_IF_NONE_MATCH' => '*'], 304],
    'If-None-Match from mod_deflate' => [['HTTP_IF_NONE_MATCH' => substr($etag, 0, -1) . '-gzip"'], 304],
    'If-None-Match from mod_brotli'  => [['HTTP_IF_NONE_MATCH' => 'W/' . substr($etag, 0, -1) . '-br"'], 304],
    'If-None-Match other'            => [['HTTP_IF_NONE_MATCH' => '"x"'], 200],
    'If-None-Match other wins over a matching date' => [['HTTP_IF_NONE_MATCH' => '"x"', 'HTTP_IF_MODIFIED_SINCE' => $lastModified], 200],
    'If-Modified-Since equal'        => [['HTTP_IF_MODIFIED_SINCE' => $lastModified], 304],
    'If-Modified-Since later'        => [['HTTP_IF_MODIFIED_SINCE' => 'Wed, 15 Nov 2023 00:00:00 GMT'], 304],
    'If-Modified-Since earlier'      => [['HTTP_IF_MODIFIED_SINCE' => 'Tue, 14 Nov 2023 22:13:19 GMT'], 200],
    'If-Modified-Since not a date'   => [['HTTP_IF_MODIFIED_SINCE' => 'now'], 200],
    'If-Modified-Since out of range' => [['HTTP_IF_MODIFIED_SINCE' => 'Thu, 99 Dec 9999 99:99:99 GMT'], 200],
] as $what => [$server, $status]) {
    expect($status, $withServer('/assets/app.js', $server, $none)?->status(), "conditional: $what");
}
$notModified = $withServer('/assets/app.js', ['HTTP_IF_NONE_MATCH' => $etag], $on);
expect([304, null, null, 'private, no-cache', $etag, 'noindex, nofollow, noarchive'], [$notModified?->status(), $notModified?->filePath(), $notModified?->header('Content-Length'), $notModified?->header('Cache-Control'), $notModified?->header('ETag'), $notModified?->header('X-Robots-Tag')], '304: no file, no length, the cache headers');

// If-Range: the part only for the version the client has.
$mp4 = static fn (string $ifRange): ?int => $withServer('/assets/clip.mp4', ['HTTP_RANGE' => 'bytes=0-1', 'HTTP_IF_RANGE' => $ifRange], $none)?->status();
$clipEtag = serveUrl('/assets/clip.mp4', $none)?->header('ETag');
$clipDate = serveUrl('/assets/clip.mp4', $none)?->header('Last-Modified');
expect([206, 206, 200], [$mp4((string) $clipEtag), $mp4((string) $clipDate), $mp4('"other"')], 'If-Range: ETag or date of this file → part, anything else → whole file');

// Files answer GET and HEAD; other methods are Kirby's business.
foreach (['OPTIONS', 'POST', 'PUT', 'DELETE'] as $method) {
    holds($withServer('/media/pages/p/image.webp', ['REQUEST_METHOD' => $method], $none) === null, "$method on a media file: Kirby decides");
}
expect(200, $withServer('/media/pages/p/image.webp', ['REQUEST_METHOD' => 'HEAD'], $none)?->status(), 'HEAD on a media file: served');

/* --------------------------------------------------- ACME challenge, enabled only */

sudare_write($public . '/.well-known/acme-challenge/Tok-en_1', 'TOKEN.THUMBPRINT');
sudare_write($public . '/.well-known/acme-challenge/a.b', 'DOT');
sudare_write($root . '/outside.txt', 'OUTSIDE');
symlink($root . '/outside.txt', $public . '/.well-known/acme-challenge/link');
$broken = Config::fromArray(['user' => 'x']);
$challenge = serveUrl('/.well-known/acme-challenge/Tok-en_1', $on);
expect([200, 'text/plain', realpath($public . '/.well-known/acme-challenge/Tok-en_1')], [$challenge?->status(), $challenge?->header('Content-Type'), $challenge?->filePath()], 'ACME: token file served as text');
expect(['noindex, nofollow, noarchive', 'private, no-store', 'nosniff'], [$challenge?->header('X-Robots-Tag'), $challenge?->header('Cache-Control'), $challenge?->header('X-Content-Type-Options')], 'ACME: protective headers');
expect(200, serveUrl('/.well-known/acme-challenge/Tok-en_1', $broken)?->status(), 'ACME: served while broken too');
foreach (['/.well-known/acme-challenge/missing', '/.well-known/acme-challenge', '/.well-known/acme-challenge/a.b',
    '/.well-known/acme-challenge/link', '/.well-known/acme-challenge/x/y'] as $url) {
    $answer = serveUrl($url, $on);
    expect([404, '', null], [$answer?->status(), $answer?->body(), $answer?->filePath()], "ACME: $url is an empty 404, never Kirby");
}
holds(serveUrl('/.well-known/acme-challenge/Tok-en_1', $none) === null, 'ACME without configuration: Kirby decides');
$acmeOptions = $withServer('/.well-known/acme-challenge/Tok-en_1', ['REQUEST_METHOD' => 'OPTIONS'], $on);
expect([404, null, 'noindex, nofollow, noarchive'], [$acmeOptions?->status(), $acmeOptions?->filePath(), $acmeOptions?->header('X-Robots-Tag')], 'ACME with OPTIONS: an empty 404, never Kirby');
holds(serveUrl('/.well-known/acme-challenge/Tok-en_1', Config::fromArray(['active' => false])) === null, 'ACME switched off: Kirby decides');

/* -------------------------------------------- subprocess: bytes out, status 200 */

// A root without any configuration: state none, so Sudare must not answer.
$plain = sudare_tmp();
$bytes = random_bytes(64) . "\x00\xff\r\n" . 'WEBP-BYTES';
sudare_write($plain . '/public/media/pages/p/probe.webp', $bytes);
$probe = sudare_php(
    'Sudare\\Sudare::guard(' . var_export($plain, true) . ');',
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/media/pages/p/probe.webp'],
    []
);
expect(0, $probe['exit'], 'subprocess: exit 0');
expect($bytes, $probe['out'], 'subprocess: stdout is exactly the file bytes');
expect(200, $probe['status'], 'subprocess: status 200');

// A part goes out as exactly those bytes.
sudare_write($plain . '/public/assets/clip.mp4', '0123456789');
$probe = sudare_php(
    'Sudare\\Sudare::guard(' . var_export($plain, true) . ');',
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assets/clip.mp4', 'HTTP_RANGE' => 'bytes=3-5'],
    []
);
expect(['345', 206], [$probe['out'], $probe['status']], 'subprocess: range 3-5 sends exactly 345 with 206');

// An output buffer must not collect the file: 24 MB through a 16 MB memory limit.
$big = $plain . '/public/assets/film.mp4';
$handle = fopen($big, 'wb');
for ($i = 0; $i < 24; $i++) {
    fwrite($handle, str_repeat(chr(65 + $i), 1024 * 1024));
}
fclose($handle);
$probe = sudare_php(
    "ini_set('memory_limit', '16M'); ob_start();\n" . 'Sudare\\Sudare::guard(' . var_export($plain, true) . ');',
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assets/film.mp4'],
    []
);
expect([0, 24 * 1024 * 1024, sha1_file($big)], [$probe['exit'], strlen($probe['out']), sha1($probe['out'])], 'subprocess: 24 MB with an output buffer and 16 MB memory_limit arrive whole');

finish('MediaTest');
