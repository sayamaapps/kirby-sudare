<?php
/**
 * Options: the three spellings, env overlay, booleans and lists from files
 * and from the environment.
 *   php tests/OptionsTest.php
 * Owned by the architect (shared file).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Options;

// get(): flat beats nested beats bare; null counts as unset.
expect('flat', Options::get(['sudare.user' => 'flat', 'sudare' => ['user' => 'nested'], 'user' => 'bare'], 'user'), 'flat spelling wins');
expect('nested', Options::get(['sudare' => ['user' => 'nested'], 'user' => 'bare'], 'user'), 'nested spelling beats bare');
expect('bare', Options::get(['user' => 'bare'], 'user'), 'bare spelling');
expect('dflt', Options::get([], 'user', 'dflt'), 'default when absent');
expect('dflt', Options::get(['sudare.user' => null, 'user' => null], 'user', 'dflt'), 'null counts as unset');
expect(false, Options::get(['active' => false], 'active', true), 'a real false is returned, not the default');

// has(): presence in any spelling, even with a broken value.
holds(Options::has(['sudare.exempt' => 'x'], 'exempt'), 'has flat');
holds(Options::has(['sudare' => ['exempt' => null]], 'exempt'), 'has nested with null value');
holds(Options::has(['exempt' => 5], 'exempt'), 'has bare');
holds(!Options::has(['sudare' => 'not-an-array'], 'exempt'), 'nested that is not an array: not present');
holds(!Options::has([], 'exempt'), 'absent');

// envKey()/env(): empty string = unset.
expect('SUDARE_USER', Options::envKey('user'), 'env key');
expect('anne', Options::env(['SUDARE_USER' => 'anne'], 'user'), 'env value');
expect(null, Options::env(['SUDARE_USER' => ''], 'user'), 'empty env value is unset');
expect('d', Options::env([], 'user', 'd'), 'env default');
holds(Options::envPresent(['SUDARE_HASH' => 'x']), 'envPresent with one key');
holds(!Options::envPresent(['SUDARE_THEME' => 'x', 'OTHER' => 'y']), 'theme and foreign keys do not count');
holds(!Options::envPresent([]), 'envPresent empty');
holds(!Options::envPresent(['SUDARE_LANGUAGE' => 'de']), 'SUDARE_LANGUAGE alone is no source (it also sets the CLI language)');
holds(Options::envPresent(['SUDARE_LANGUAGE' => 'de', 'SUDARE_REALM' => 'x']), 'with another key the environment is a source');

// mergeEnv(): env wins per key and removes the other spellings of that key.
$merged = Options::mergeEnv(['sudare.user' => 'file', 'sudare' => ['user' => 'file2', 'realm' => 'R'], 'hash' => 'h'], ['SUDARE_USER' => 'env', 'SUDARE_THEME' => 'ignored']);
expect('env', Options::get($merged, 'user'), 'env user wins');
expect('R', Options::get($merged, 'realm'), 'untouched nested key survives');
expect('h', Options::get($merged, 'hash'), 'untouched bare key survives');
expect(null, Options::get($merged, 'theme'), 'theme cannot come from the environment');
expect(['hash' => 'h'], Options::mergeEnv(['hash' => 'h'], []), 'no env: unchanged');

// bool(): real booleans and the usual strings; anything else is null.
expect(true, Options::bool(true), 'bool true');
expect(false, Options::bool(false), 'bool false');
foreach (['true', '1', 'yes', 'on', ' TRUE '] as $v) {
    expect(true, Options::bool($v), "bool('$v')");
}
foreach (['false', '0', 'no', 'off', 'Off'] as $v) {
    expect(false, Options::bool($v), "bool('$v')");
}
foreach (['', 'maybe', 1, 0, null, []] as $v) {
    expect(null, Options::bool($v), 'bool(' . var_export($v, true) . ') is null');
}

// list(): arrays and comma strings, trimmed, empties dropped; otherwise null.
expect(['/a', '/b'], Options::list(['/a', ' /b ', '']), 'list from array');
expect(['/a', '/b'], Options::list('/a, /b,,'), 'list from string');
expect([], Options::list(''), 'empty string is an empty list');
expect(null, Options::list(['/a', 5]), 'non-string entry: null');
expect(null, Options::list(5), 'non-list: null');
expect(null, Options::list(null), 'null: null');

// Constants are consistent.
expect([], array_values(array_diff(Options::ENV_KEYS, Options::KEYS)), 'every env key is a key');
expect([], array_values(array_diff(Options::SECRET_KEYS, Options::KEYS)), 'every secret key is a key');
holds(!in_array('theme', Options::ENV_KEYS, true), 'theme is not an env key');

finish('Sudare Options');
