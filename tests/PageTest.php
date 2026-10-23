<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

// SPEC.md §10.4: the 401 page, the 503 text and the realm header.

$en = Sudare\Config::fromArray(['user' => 'test', 'hash' => sudare_hash()]);
$de = Sudare\Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'language' => 'de']);
$themed = Sudare\Config::fromArray([
    'user' => 'test',
    'hash' => sudare_hash(),
    'theme' => ['chrome' => '#abc', 'paper' => '#FCFAF8'],
]);

/* ---------------------------------------------------------------- denied() */

$html = Sudare\Page::denied($en);
holds(str_starts_with($html, '<!doctype html>'), 'denied: starts with <!doctype html>');
holds(str_contains($html, 'lang="en"'), 'denied: English lang attribute');
holds(str_contains($html, '<meta name="viewport"'), 'denied: viewport meta');
holds(str_contains($html, '<meta name="robots" content="noindex, nofollow, noarchive">'), 'denied: robots meta');
holds(str_contains($html, 'theme-color" content="#1E3648"'), 'denied: default theme-color');
holds(str_contains($html, 'This site is not public yet'), 'denied: English heading');
holds(str_contains($html, 'It is under construction and can only be reached with access credentials.'), 'denied: English text');
holds(!str_contains($html, 'Preview') || str_contains($html, '<title>Preview</title>'), 'denied: English title');
holds(str_contains($html, '<title>Preview</title>'), 'denied: English title is Preview');
holds(!str_contains($html, 'Vorschau'), 'denied: no German text in English page');
holds(!str_contains($html, 'test'), 'denied: user not present');
holds(!str_contains($html, 'test-password'), 'denied: password not present');
holds(!str_contains($html, '<script'), 'denied: no script');
holds(!str_contains($html, 'http://') && !str_contains($html, 'https://'), 'denied: no external URL');
holds(!str_contains($html, '$2y$'), 'denied: no hash');
expect($html, Sudare\Page::denied($en), 'denied: deterministic');

$htmlDe = Sudare\Page::denied($de);
holds(str_contains($htmlDe, 'lang="de"'), 'denied: German lang attribute');
holds(str_contains($htmlDe, 'Diese Seite ist noch nicht öffentlich'), 'denied: German heading');
holds(str_contains($htmlDe, 'Sie befindet sich im Aufbau und ist nur mit Zugangsdaten erreichbar.'), 'denied: German text');
holds(str_contains($htmlDe, '<title>Vorschau</title>'), 'denied: German title');

$htmlThemed = Sudare\Page::denied($themed);
holds(str_contains($htmlThemed, 'theme-color" content="#ABC"'), 'denied: custom chrome, upper-cased by Config');
holds(str_contains($htmlThemed, 'background:#FCFAF8'), 'denied: custom paper');
holds(str_contains($htmlThemed, 'color:#1E3648'), 'denied: default deep kept for partial theme');

// The CSP source is the hash of exactly the style element the page carries,
// so a site with style-src 'self' can allow it (the browser checks the same bytes).
foreach (['default' => [$en, $html], 'themed' => [$themed, $htmlThemed]] as $what => [$config, $page]) {
    preg_match('~<style>(.*?)</style>~s', $page, $m);
    expect(
        "'sha256-" . base64_encode(hash('sha256', $m[1] ?? '', true)) . "'",
        Sudare\Page::styleSource($config),
        "styleSource ($what): hash of the page's style element"
    );
}
holds(Sudare\Page::styleSource($en) !== Sudare\Page::styleSource($themed), 'styleSource: changes with the theme');

/* ---------------------------------------------------------- unavailable() */

expect("The preview is temporarily unavailable.\n", Sudare\Page::unavailable($en), 'unavailable: English sentence plus newline');
expect("Die Vorschau ist vorübergehend nicht verfügbar.\n", Sudare\Page::unavailable($de), 'unavailable: German sentence plus newline');
holds(!str_contains(Sudare\Page::unavailable($en), '/'), 'unavailable: no path-like content');
holds(!str_contains(Sudare\Page::unavailable($en), '$2y$'), 'unavailable: no hash');

/* ------------------------------------------------------------ realmHeader() */

expect('Basic realm="Preview", charset="UTF-8"', Sudare\Page::realmHeader('Preview'), 'realm: plain');
expect('Basic realm="Preview — Demo", charset="UTF-8"', Sudare\Page::realmHeader('Preview — Demo'), 'realm: plain with dash');
expect('Basic realm="Vorschau — Demo", charset="UTF-8"', Sudare\Page::realmHeader('Vorschau — Demo'), 'realm: UTF-8 kept');
expect('Basic realm="He said hiback", charset="UTF-8"', Sudare\Page::realmHeader('He said "hi"\\back'), 'realm: quotes and backslash removed');
expect('Basic realm="ab", charset="UTF-8"', Sudare\Page::realmHeader("a\r\nb"), 'realm: CR and LF removed');
expect('Basic realm="ab", charset="UTF-8"', Sudare\Page::realmHeader("a\0b"), 'realm: NUL removed');
expect('Preview', (string) preg_replace('/^Basic realm="(.*)", charset.*$/', '$1', Sudare\Page::realmHeader('"\\')), 'realm: only quote and backslash collapses to Preview');
expect('Basic realm="Preview", charset="UTF-8"', Sudare\Page::realmHeader(''), 'realm: empty becomes Preview');
expect('Basic realm="Preview", charset="UTF-8"', Sudare\Page::realmHeader("\r\n\""), 'realm: only control characters become Preview');
holds(!str_contains(Sudare\Page::realmHeader("x\r\nSet-Cookie: y"), "\n"), 'realm: no line break survives');

finish('PageTest');
