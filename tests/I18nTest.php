<?php
/**
 * Translations: every language has the same keys and placeholders as
 * English, no value is empty, the keys the code relies on exist, and
 * Sudare::translations() prefixes them for Kirby.
 *   php tests/I18nTest.php
 * Owned by the architect (shared file).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\I18n;
use Sudare\Sudare;

$placeholders = function (string $text): array {
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
};

$en = I18n::table('en');
holds(count($en) > 30, 'English table loaded');
foreach (I18n::LANGUAGES as $language) {
    $table = I18n::table($language);
    expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
    expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
    foreach ($en as $key => $text) {
        holds(isset($table[$key]) && is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
        expect($placeholders($text), $placeholders($table[$key] ?? ''), "$language.$key: same placeholders as English");
    }
}
expect([], I18n::table('fr'), 'unknown language: no table');

// Every key the code relies on exists (SPEC.md §5).
$required = [
    'realm', 'denied.pageTitle', 'denied.heading', 'denied.text', 'unavailable.text',
    'cli.hash.prompt', 'cli.hash.empty', 'cli.hash.short', 'cli.hash.long', 'cli.hash.mismatch', 'cli.hash.info', 'cli.hash.output',
    'cli.hash.token', 'cli.hash.usage',
    'cli.check.none', 'cli.check.off', 'cli.check.allowOff', 'cli.check.broken', 'cli.check.ok', 'cli.check.token',
    'cli.check.exempt', 'cli.check.env', 'cli.check.files', 'cli.check.style', 'cli.check.usage', 'word.yes', 'word.no',
];
foreach ($required as $key) {
    holds(isset($en[$key]), "key $key exists");
}
// One reason text per reason code Config can produce (SPEC.md §4.4).
foreach (['unreadable', 'syntax', 'notArray', 'exit', 'active', 'user', 'hash', 'example', 'token', 'realm', 'exempt', 'language', 'media', 'assets', 'theme'] as $code) {
    holds(isset($en['reason.' . $code]), "reason.$code exists");
}

I18n::setLanguage('fr');
expect('en', I18n::language(), 'unknown language falls back to English');
expect('Preview', I18n::t('realm'), 'English default');
expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
expect('The password is shorter than 8 characters. Please choose something longer.', I18n::t('cli.hash.short', ['min' => 8]), 'placeholders replaced');

I18n::setLanguage('de');
expect('de', I18n::language(), 'German selected');
expect('Vorschau', I18n::t('realm'), 'German string');
expect('Diese Seite ist noch nicht öffentlich', I18n::t('denied.heading'), 'German heading');
I18n::setLanguage('en');

// Kirby registration helper: same keys, prefixed.
$translations = Sudare::translations();
expect(['en', 'de'], array_keys($translations), 'translations() has both languages');
expect(count($en), count($translations['en']), 'translations() has every key');
expect('Preview', $translations['en']['sudare.realm'] ?? null, 'translations() prefixes with sudare.');
expect('Vorschau', $translations['de']['sudare.realm'] ?? null, 'German translations() entry');

// No German leaks into the English table (code and defaults are English).
foreach (['Vorschau', 'Passwort', 'Zugangsdaten'] as $word) {
    foreach ($en as $key => $text) {
        holds(!str_contains($text, $word), "en.$key contains no German word '$word'");
    }
}

finish('Sudare I18n');
