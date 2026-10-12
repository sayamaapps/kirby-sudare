<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Config;

// SPEC.md §10.2: Config — sources, validation, reason codes, spellings, theme, toArray.

$hash = sudare_hash();
$root = sudare_tmp();

/** Loads one configuration file written into a fresh root. */
function cfgFile(string $name, string $content): Config
{
    $root = sudare_tmp();
    sudare_write($root . '/site/config/' . $name, $content);
    return Config::load($root, []);
}

/** A config from an array, as a sudare.php would return it. */
function cfgArray(array $raw, array $env = []): Config
{
    return Config::fromArray($raw, $env);
}

// --- sources ---------------------------------------------------------------
$empty = Config::load(sudare_tmp(), []);
expect(Config::STATE_NONE, $empty->state(), 'empty root: state none');
expect(null, $empty->source(), 'empty root: no source');
holds(!$empty->protects() && !$empty->enabled(), 'empty root: neither protects nor enabled');

$valid = sudare_config($root, ['user' => ' test ', 'hash' => $hash]);
$config = Config::load($root, []);
expect(Config::STATE_ON, $config->state(), 'sudare.php valid: on');
expect('sudare.php', $config->source(), 'sudare.php valid: source');
expect('test', $config->user(), 'sudare.php valid: user trimmed');
expect('bcrypt', $config->algorithm(), 'sudare.php valid: bcrypt');
expect('en', $config->language(), 'sudare.php valid: language en');
expect('Preview', $config->realm(), 'sudare.php valid: default realm');
holds($config->protects() && $config->enabled(), 'sudare.php valid: protects');

$legacyRoot = sudare_tmp();
sudare_config($legacyRoot, ['vorschau' => ['benutzer' => 'x', 'hash' => $hash, 'realm' => 'Vorschau Foo']], 'credentials.php');
$legacy = Config::load($legacyRoot, []);
expect(Config::STATE_ON, $legacy->state(), 'credentials.php legacy: on');
expect('credentials.php', $legacy->source(), 'credentials.php legacy: source');
expect('de', $legacy->language(), 'credentials.php legacy: language de');
expect('Vorschau Foo', $legacy->realm(), 'credentials.php legacy: realm from file');
expect('x', $legacy->user(), 'credentials.php legacy: benutzer mapped to user');

$legacyNoRealm = cfgFile('credentials.php', "<?php return ['vorschau' => ['benutzer' => 'x', 'hash' => " . var_export($hash, true) . "]];");
expect('Vorschau', $legacyNoRealm->realm(), 'credentials.php legacy without realm: Vorschau');

$both = sudare_tmp();
sudare_config($both, ['user' => 'new', 'hash' => $hash]);
sudare_config($both, ['vorschau' => ['benutzer' => 'old', 'hash' => $hash]], 'credentials.php');
expect('new', Config::load($both, [])->user(), 'both files: sudare.php wins');

$legacyEnRoot = sudare_tmp();
sudare_config($legacyEnRoot, ['vorschau' => ['benutzer' => 'x', 'hash' => $hash]], 'credentials.php');
expect('en', Config::load($legacyEnRoot, ['SUDARE_LANGUAGE' => 'en'])->language(), 'legacy + SUDARE_LANGUAGE=en: en');
expect('Eigen', Config::load($legacyEnRoot, ['SUDARE_REALM' => 'Eigen'])->realm(), 'legacy + SUDARE_REALM: overrides');

$englishLegacy = cfgFile('credentials.php', "<?php return ['user' => 'x', 'hash' => " . var_export($hash, true) . "];");
expect('en', $englishLegacy->language(), 'credentials.php with English keys: read as new, language en');

// --- broken configurations --------------------------------------------------
$brokenCases = [
    ['', 'notArray', 'empty file'],
    ["<?php return [;", 'syntax', 'syntax error'],
    ['<?php return "x";', 'notArray', 'returns a string'],
    ['<?php return ["vorschau" => "x"];', 'user', 'legacy key with non-array'],
    ['<?php return ["user" => "a:b", "hash" => ' . var_export($hash, true) . '];', 'user', 'colon in user'],
    ['<?php return ["user" => "x", "hash" => "plain-password"];', 'hash', 'plain text hash'],
    ['<?php return ["user" => "x", "hash" => "$2y$12$ERSETZEN.ERSETZEN.ERSETZEN.ERSETZEN.ERSETZEN.ERSETZEN"];', 'example', 'example hash ERSETZEN'],
    ['<?php return ["user" => "x", "hash" => "$2y$12$REPLACE.REPLACE.REPLACE.REPLACE.REPLACE.REPLACE"];', 'example', 'example hash REPLACE'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "token" => "plain"];', 'token', 'plain token'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "realm" => 5];', 'realm', 'realm not a string'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "exempt" => "api"];', 'exempt', 'exempt without slash'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "exempt" => ["/"]];', 'exempt', 'exempt root only'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "exempt" => [5]];', 'exempt', 'exempt non-string'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "language" => "fr"];', 'language', 'unknown language'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "media" => "x"];', 'media', 'media not a bool'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "assets" => 1];', 'assets', 'assets not a bool'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "theme" => "red"];', 'theme', 'theme not an array'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "theme" => ["chrome" => "red"]];', 'theme', 'theme colour invalid'],
    ['<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . ', "theme" => ["foo" => "#fff"]];', 'theme', 'theme unknown key'],
    ['<?php return ["active" => "maybe", "user" => "x", "hash" => ' . var_export($hash, true) . '];', 'active', 'active not a bool'],
    ['<?php return ["user" => "", "hash" => ' . var_export($hash, true) . '];', 'user', 'empty user'],
    ['<?php return ["user" => "   ", "hash" => ' . var_export($hash, true) . '];', 'user', 'blank user'],
    ['<?php return ["user" => "a\nb", "hash" => ' . var_export($hash, true) . '];', 'user', 'newline in user'],
    ['<?php return ["user" => 5, "hash" => ' . var_export($hash, true) . '];', 'user', 'non-string user'],
];
foreach ($brokenCases as [$content, $reason, $what]) {
    $config = cfgFile('sudare.php', $content);
    expect(Config::STATE_BROKEN, $config->state(), "broken ($what): state");
    expect($reason, $config->reason(), "broken ($what): reason");
}

// Array or numeric values must be rejected without a PHP warning (the warning
// would otherwise reach the page before any header is sent).
$warnings = [];
set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$arrayLanguage = cfgArray(['user' => 'x', 'hash' => $hash, 'language' => ['en']]);
$numberLanguage = cfgArray(['user' => 'x', 'hash' => $hash, 'language' => 5]);
$arrayToken = cfgArray(['user' => 'x', 'hash' => $hash, 'token' => ['x']]);
restore_error_handler();
expect([], $warnings, 'array-valued options: no PHP warning');
expect('language', $arrayLanguage->reason(), 'language as array: broken language');
expect('language', $numberLanguage->reason(), 'language as number: broken language');
expect('token', $arrayToken->reason(), 'token as array: broken token');

$leak = sudare_tmp();
sudare_write($leak . '/site/config/sudare.php', '<?php echo "LEAK"; return [];');
$level = ob_get_level();
ob_start();
$leakConfig = Config::load($leak, []);
$printed = ob_get_clean();
expect('', $printed, 'broken config: nothing printed while loading');
expect($level, ob_get_level(), 'broken config: output buffers balanced');
expect('user', $leakConfig->reason(), 'broken config with echo: reason user');

expect(Config::STATE_OFF, cfgArray(['user' => 'x', 'hash' => $hash, 'active' => 'false'])->state(), 'active string false: off');
$off = cfgArray(['active' => false, 'user' => 'x']);
expect(Config::STATE_OFF, $off->state(), 'active false: off');
expect(null, $off->user(), 'active false: user null');
expect(null, $off->hash(), 'active false: hash null');
holds(!$off->enabled(), 'active false: not enabled');

// open_basedir: a config link to outside looks absent to file_exists() and
// is_link(); it must still count as present (broken), never as "none".
foreach (['site/config/sudare.php' => 'file', 'site/config' => 'directory'] as $linked => $what) {
    $jail = sudare_tmp();
    $outside = sudare_tmp();
    if ($what === 'file') {
        file_put_contents($outside . '/sudare.php', '<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . '];');
        symlink($outside . '/sudare.php', $jail . '/site/config/sudare.php');
    } else {
        rename($jail . '/site/config', $outside . '/config');
        symlink($outside . '/config', $jail . '/site/config');
    }
    $process = proc_open(
        [PHP_BINARY, '-d', 'open_basedir=' . $jail . PATH_SEPARATOR . dirname(__DIR__), '-r',
            'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . '; echo Sudare\Config::load(' . var_export($jail, true) . ')->state();'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $state = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    expect(Config::STATE_BROKEN, $state, "open_basedir, $linked as a link to outside: broken, not none");
}

// Round 2h: the same link in a site/config without read permission (search
// only, 0311): scandir() sees nothing, PHP's open_basedir warning still tells.
// A missing file there stays "none". Root lists anyway: skipped.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    holds(true, 'open_basedir + unreadable site/config: skipped (running as root)');
} else {
    $obState = static function (string $jail, string $before = ''): string {
        $process = proc_open(
            [PHP_BINARY, '-d', 'open_basedir=' . $jail . PATH_SEPARATOR . dirname(__DIR__), '-r',
                $before . 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . '; echo Sudare\\Config::load(' . var_export($jail, true) . ')->state();'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $state = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $state;
    };
    $jail = sudare_tmp();
    $outside = sudare_tmp();
    file_put_contents($outside . '/sudare.php', '<?php return ["user" => "x", "hash" => ' . var_export($hash, true) . '];');
    symlink($outside . '/sudare.php', $jail . '/site/config/sudare.php');
    chmod($jail . '/site/config', 0311);
    expect(Config::STATE_BROKEN, $obState($jail), 'open_basedir, link to outside in an unreadable site/config: broken, not none');
    // Round 2i: a site's error handler set before guard() that swallows
    // suppressed warnings (returns null) must not hide the open_basedir one.
    expect(Config::STATE_BROKEN, $obState($jail, 'set_error_handler(function ($no) { if (!(error_reporting() & $no)) { return; } throw new ErrorException("x"); });'),
        'open_basedir + unreadable site/config + a foreign error handler: broken');
    expect(Config::STATE_BROKEN, $obState($jail, 'set_error_handler(fn () => true);'), 'open_basedir + unreadable site/config + a handler returning true: broken');
    // Round 2j: a handler that throws on every warning (Kirby's debug mode,
    // many apps) must not turn the open_basedir warning of is_file() into an
    // exception — a 500 without protective headers.
    expect(Config::STATE_BROKEN, $obState($jail, 'set_error_handler(function ($no, $message, $file, $line) { throw new ErrorException($message, 0, $no, $file, $line); });'),
        'open_basedir + a foreign handler that throws: broken, not an exception');
    chmod($jail . '/site/config', 0755);
    unlink($jail . '/site/config/sudare.php');
    chmod($jail . '/site/config', 0311);
    expect(Config::STATE_NONE, $obState($jail), 'open_basedir, nothing in an unreadable site/config: none');
    chmod($jail . '/site/config', 0755);
}

// Round 2i: opcache.validate_timestamps=0 must not keep a rotated password.
if (function_exists('opcache_invalidate')) {
    $rot = sudare_tmp();
    $write = static fn (string $user): bool => (bool) file_put_contents($rot . '/site/config/sudare.php', '<?php return ["user" => ' . var_export($user, true) . ', "hash" => ' . var_export($hash, true) . '];');
    $write('erster');
    $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
        . ' echo Sudare\Config::load(' . var_export($rot, true) . ')->user(), "|";'
        . ' file_put_contents(' . var_export($rot . '/site/config/sudare.php', true) . ', ' . var_export('<?php return ["user" => "zweiter", "hash" => ' . var_export($hash, true) . '];', true) . ');'
        . ' echo Sudare\Config::load(' . var_export($rot, true) . ')->user();';
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0', '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $users = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    expect('erster|zweiter', $users, 'opcache without timestamp checks: the rewritten file counts at once');

    // Round 2j: dropping the copy must not waste shared opcache memory while
    // the file is unchanged (a forced invalidation marked it stale on every
    // request: ~1 KB each until the cache restarted, for every site of the
    // pool). Each load here is one request: the per-request cache is cleared.
    $waste = sudare_tmp();
    sudare_config($waste, ['user' => 'x', 'hash' => $hash]);
    // $before runs first (a site's handler); without $memory only the state is
    // printed (opcache_get_status() is restricted too under restrict_api).
    $measure = static function (array $flags, string $before = '', bool $memory = true) use ($waste): string {
        $code = $before . 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . ' $load = static function (): string { (new ReflectionProperty(Sudare\Config::class, "read"))->setValue(null, []); return Sudare\Config::load(' . var_export($waste, true) . ')->state(); };'
            . ' $wasted = static fn (): int => (int) ((opcache_get_status(false) ?: [])["memory_usage"]["wasted_memory"] ?? -1);'
            . ' $load(); ' . ($memory ? '$start = $wasted(); ' : '') . 'for ($i = 0; $i < 50; $i++) { $load(); }'
            . ' echo ' . ($memory ? '$wasted() - $start, "|", ' : '') . '$load();';
        $process = proc_open(array_merge([PHP_BINARY], $flags, ['-r', $code]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $out;
    };
    expect('0|on', $measure(['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0']), 'opcache: 50 requests for an unchanged file waste no shared memory');
    // Under opcache.restrict_api the call is refused with a warning; a site's
    // handler that throws on warnings must not make that a 500.
    expect('on', $measure(['-d', 'opcache.enable_cli=1', '-d', 'opcache.restrict_api=/nowhere'],
        'set_error_handler(function ($no, $message, $file, $line) { throw new ErrorException($message, 0, $no, $file, $line); });', false),
        'opcache.restrict_api + a foreign handler that throws: still on, not an exception');
} else {
    holds(true, 'opcache rotation: skipped (no opcache)');
}

// dangling symlink and unreadable file
$link = sudare_tmp();
symlink($link . '/does-not-exist', $link . '/site/config/sudare.php');
$dangling = Config::load($link, []);
expect(Config::STATE_BROKEN, $dangling->state(), 'dangling symlink: broken');
expect('unreadable', $dangling->reason(), 'dangling symlink: unreadable');

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $locked = sudare_tmp();
    $file = sudare_config($locked, ['user' => 'x', 'hash' => $hash]);
    chmod($file, 0000);
    $lockedConfig = Config::load($locked, []);
    chmod($file, 0600);
    expect('unreadable', $lockedConfig->reason(), 'unreadable file: unreadable');
} else {
    holds(true, 'unreadable file: skipped (running as root)');
}

// Round 2m: a bcrypt hash with the $2a$/$2b$ prefix (python-bcrypt, htpasswd)
// verifies with password_verify() but is "unknown" to password_get_info();
// it must count as a hash, not fail closed. A pasted trailing newline too.
$foreign = crypt('test-password', '$2a$04$abcdefghijklmnopqrstuu');
holds(password_verify('test-password', $foreign) && str_starts_with($foreign, '$2a$04$'), 'fixture: $2a$ hash verifies');
$foreignConfig = cfgArray(['user' => 'x', 'hash' => $foreign]);
expect([Config::STATE_ON, 'bcrypt'], [$foreignConfig->state(), $foreignConfig->algorithm()], '$2a$ bcrypt hash: on, algorithm bcrypt');
expect(Config::STATE_ON, cfgArray(['user' => 'x', 'hash' => str_replace('$2a$', '$2b$', $foreign)])->state(), '$2b$ bcrypt hash: on');
expect(Config::STATE_BROKEN, cfgArray(['user' => 'x', 'hash' => substr($foreign, 0, 59)])->state(), '$2a$ hash one character short: broken');
expect(Config::STATE_BROKEN, cfgArray(['user' => 'x', 'hash' => '$2a$04$'])->state(), '$2a$ prefix alone: broken (never a prefix test)');
expect(Config::STATE_ON, cfgArray(['user' => 'x', 'hash' => $hash . "\n"])->state(), 'hash with a trailing newline: on');
expect(Config::STATE_ON, cfgArray(['user' => 'x', 'hash' => $hash, 'token' => ' ' . $hash . "\n"])->state(), 'token with surrounding whitespace: on');
expect(Config::STATE_ON, Config::load(sudare_tmp(), ['SUDARE_USER' => 'env', 'SUDARE_HASH' => $hash . "\n"])->state(), 'SUDARE_HASH with a trailing newline: on');
expect(null, Config::exemptEntry('/feed//*'), 'exempt: a double slash is refused as written, not normalised');
expect(null, Config::exemptEntry('/a//b'), 'exempt: a double slash inside is refused');

// --- environment -----------------------------------------------------------
$envOnly = Config::load(sudare_tmp(), ['SUDARE_USER' => 'env', 'SUDARE_HASH' => $hash]);
expect(Config::STATE_ON, $envOnly->state(), 'env only: on');
expect('environment', $envOnly->source(), 'env only: source environment');
expect(Config::STATE_OFF, Config::load(sudare_tmp(), ['SUDARE_USER' => 'env', 'SUDARE_HASH' => $hash, 'SUDARE_ACTIVE' => 'off'])->state(), 'SUDARE_ACTIVE=off: off');
expect(['/api/hook', '/x'], Config::load(sudare_tmp(), ['SUDARE_USER' => 'env', 'SUDARE_HASH' => $hash, 'SUDARE_EXEMPT' => '/api/hook, /x/'])->exempt(), 'SUDARE_EXEMPT: list normalised');
expect(Config::DEFAULT_THEME, Config::load(sudare_tmp(), ['SUDARE_USER' => 'env', 'SUDARE_HASH' => $hash, 'SUDARE_THEME' => 'red'])->theme(), 'SUDARE_THEME is ignored');
expect(Config::STATE_NONE, Config::load(sudare_tmp(), ['SUDARE_FOO' => 'x'])->state(), 'unknown SUDARE_FOO alone: none');

// --- spellings ---------------------------------------------------------------
expect(Config::STATE_ON, cfgArray(['sudare' => ['user' => 'n', 'hash' => $hash]])->state(), 'nested spelling: on');
expect(Config::STATE_ON, cfgArray(['sudare.user' => 'f', 'hash' => $hash])->state(), 'flat spelling: on');
expect('f', cfgArray(['sudare.user' => 'f', 'sudare' => ['user' => 'n'], 'hash' => $hash])->user(), 'flat beats nested');
expect('n', cfgArray(['sudare' => ['user' => 'n'], 'user' => 'b', 'hash' => $hash])->user(), 'nested beats bare');

// --- exempt ------------------------------------------------------------------
$none = Config::none();
holds($none->isExempt('/.well-known/acme-challenge'), 'ACME is always exempt');
holds($none->isExempt('/.well-known/acme-challenge/x'), 'ACME sub-path always exempt');
$api = cfgArray(['user' => 'x', 'hash' => $hash, 'exempt' => ['/api/hook']]);
holds($api->isExempt('/api/hook'), 'exempt exact');
holds(!$api->isExempt('/api/hook/1'), 'exact entry: sub-path not exempt');
$below = cfgArray(['user' => 'x', 'hash' => $hash, 'exempt' => ['/api/hook/*', 'post /pay']]);
expect(['/api/hook/*', 'POST /pay'], $below->exempt(), 'exempt(): normal form, method upper-cased');
expect(['/a', '/b/*'], cfgArray(['user' => 'x', 'hash' => $hash, 'exempt' => ['/a/', '/b/*']])->exempt(), 'exempt(): one trailing slash is fine');
holds($below->isExempt('/api/hook/1') && $below->isExempt('/api/hook') && !$below->isExempt('/api/hooks'), '/* entry: path and below, not a sibling');
holds($below->isExempt('/pay', 'POST') && !$below->isExempt('/pay', 'GET') && !$below->isExempt('/pay'), 'method entry: that method only');
foreach (['/*', '/a*b', '/a/*/b', 'POST', 'POST api', 'PO-ST /x', '/x y', '/a/%2A', '/x/../panel/*', '/a/./b', '/a//b', '/a//', '/%61pi', '/a%zz', 'FOO /x', 'PURGE /x', '/lang:de/*', '/blog/tag:x/*', '/x:y', '/a;b/*', '/a~b', '/über', '/a@b', '/a,b', 'OPTIONS /x', 'options /x/*'] as $bad) {
    expect('exempt', cfgArray(['user' => 'x', 'hash' => $hash, 'exempt' => [$bad]])->reason(), "exempt entry '$bad': broken");
}
holds(!$api->isExempt('/api/hooks'), 'exempt not a string prefix');
holds(!$api->isExempt('/api'), 'exempt parent not matched');
expect(false, Config::startsWithSegment('/api/hooks', '/api/hook'), 'startsWithSegment: no partial match');

// --- theme -------------------------------------------------------------------
$themed = cfgArray(['user' => 'x', 'hash' => $hash, 'theme' => ['ink' => '#abc', 'chrome' => '#11223344']]);
expect('#ABC', $themed->theme()['ink'], 'theme: short form upper-cased');
expect('#11223344', $themed->theme()['chrome'], 'theme: 8-digit accepted');
expect(Config::DEFAULT_THEME['paper'], $themed->theme()['paper'], 'theme: missing keys keep defaults');

// --- option() and toArray() ---------------------------------------------------
$full = cfgArray(['user' => 'x', 'hash' => $hash, 'token' => sudare_hash('t'), 'exempt' => ['/api/hook'], 'realm' => 'Mine']);
expect(null, $full->option('hash'), 'option: hash is never returned');
expect(null, $full->option('token'), 'option: token is never returned');
expect('Mine', $full->option('realm'), 'option: realm raw value');
expect('d', $full->option('nope', 'd'), 'option: default for unknown key');
$shape = $full->toArray();
expect(['state', 'source', 'reason', 'user', 'algorithm', 'realm', 'token', 'exempt', 'language', 'media', 'assets'], array_keys($shape), 'toArray: keys');
expect([Config::STATE_ON, 'array', null, 'x', 'bcrypt', 'Mine', true, ['/api/hook'], 'en', true, true], array_values($shape), 'toArray: values');
holds(!str_contains(json_encode($shape), '$2y$'), 'toArray: no hash in output');

// --- legacy helpers -----------------------------------------------------------
holds(Config::isLegacy(['vorschau' => ['benutzer' => 'x']]), 'isLegacy: vorschau array');
holds(!Config::isLegacy(['user' => 'x']), 'isLegacy: English keys are not legacy');
expect(['user' => 'x', 'hash' => 'h', 'realm' => 'r'], Config::fromLegacy(['vorschau' => ['benutzer' => 'x', 'hash' => 'h', 'realm' => 'r']]), 'fromLegacy: mapping');

// --- examples never validate --------------------------------------------------
$example = Config::fromFile(dirname(__DIR__) . '/examples/sudare.php', []);
expect(Config::STATE_BROKEN, $example->state(), 'examples/sudare.php: broken');
expect('example', $example->reason(), 'examples/sudare.php: example reason');
$exampleLegacy = Config::fromFile(dirname(__DIR__) . '/examples/credentials.php', []);
expect(Config::STATE_BROKEN, $exampleLegacy->state(), 'examples/credentials.php: broken');
holds(in_array($exampleLegacy->reason(), ['example', 'hash'], true), 'examples/credentials.php: example or hash reason');

// --- switched off: media and assets still count -----------------------------
$parked = Config::fromArray(['active' => false, 'media' => false, 'assets' => false]);
expect([Config::STATE_OFF, false, false], [$parked->state(), $parked->media(), $parked->assets()], 'off: media/assets false hand files back to Kirby');
$parkedEnv = Config::fromArray([], ['SUDARE_ACTIVE' => 'false', 'SUDARE_ASSETS' => 'false']);
expect([Config::STATE_OFF, true, false], [$parkedEnv->state(), $parkedEnv->media(), $parkedEnv->assets()], 'off from the environment: SUDARE_ASSETS=false counts');
expect([Config::STATE_BROKEN, 'media'], [Config::fromArray(['active' => false, 'media' => 'maybe'])->state(), Config::fromArray(['active' => false, 'media' => 'maybe'])->reason()], 'off with an invalid media flag: broken');

// --- "not set": null and '' alike, for every optional key -------------------
$valid = ['user' => 'test', 'hash' => sudare_hash()];
foreach (['active', 'token', 'language', 'media', 'assets', 'theme', 'realm', 'exempt'] as $key) {
    foreach (['null' => null, 'empty' => '', 'blank' => '  '] as $what => $value) {
        expect(Config::STATE_ON, Config::fromArray($valid + [$key => $value])->state(), "$key => $what: not set, still on");
    }
}
expect(false, Config::fromArray($valid + ['token' => ''])->hasToken(), "token => '': no token");

// --- legacy file with English keys next to `vorschau` -----------------------
$legacyMixed = ['vorschau' => ['benutzer' => 'test', 'hash' => sudare_hash()]];
expect(Config::STATE_OFF, Config::fromArray($legacyMixed + ['active' => false])->state(), 'legacy + active => false: parked');
expect(['/api/hook'], Config::fromArray($legacyMixed + ['exempt' => ['/api/hook']])->exempt(), 'legacy + exempt: applied');
expect(true, Config::fromArray($legacyMixed + ['sudare.token' => sudare_hash('t')])->hasToken(), 'legacy + flat token: applied');
expect(['de', 'Vorschau'], [Config::fromArray($legacyMixed + ['exempt' => ['/x']])->language(), Config::fromArray($legacyMixed)->realm()], 'legacy + English keys: still German');

// --- a legacy file that cannot be read speaks German too --------------------
$legacyBroken = sudare_tmp();
sudare_write($legacyBroken . '/site/config/credentials.php', '<?php return [');
expect(['syntax', 'de'], [Config::load($legacyBroken)->reason(), Config::load($legacyBroken)->language()], 'legacy file with a syntax error: German');
expect('en', Config::load($legacyBroken, ['SUDARE_LANGUAGE' => 'en'])->language(), 'legacy file with a syntax error + SUDARE_LANGUAGE=en: English');
$newBroken = sudare_tmp();
sudare_write($newBroken . '/site/config/sudare.php', '<?php return [');
expect('en', Config::load($newBroken)->language(), 'sudare.php with a syntax error: English');

// --- a directory PHP cannot use hides the file: broken, never open ----------
$hidden = sudare_tmp();
sudare_config($hidden, ['user' => 'test', 'hash' => sudare_hash()]);
expect(Config::STATE_ON, Config::load($hidden)->state(), 'control: usable site/config is on');
rename($hidden . '/site/config', $hidden . '/site/elsewhere');
symlink($hidden . '/site/gone', $hidden . '/site/config');
expect([Config::STATE_BROKEN, 'unreadable', 'site/config'], [Config::load($hidden)->state(), Config::load($hidden)->reason(), Config::load($hidden)->source()], 'site/config as a dangling link: broken');
unlink($hidden . '/site/config');
symlink($hidden . '/site/elsewhere', $hidden . '/site/config');
expect(Config::STATE_ON, Config::load($hidden)->state(), 'site/config as a working link: on');
// The search bit binds no root: the check would see everything anyway.
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    chmod($hidden . '/site/elsewhere', 0600);
    expect([Config::STATE_BROKEN, 'unreadable'], [Config::load($hidden)->state(), Config::load($hidden)->reason()], 'site/config without the search bit: broken');
    chmod($hidden . '/site/elsewhere', 0755);
    chmod($hidden . '/site', 0600);
    expect([Config::STATE_BROKEN, 'site'], [Config::load($hidden)->state(), Config::load($hidden)->source()], 'site/ without the search bit: broken');
    chmod($hidden . '/site', 0755);
}
expect(Config::STATE_NONE, Config::load(sudare_tmp() . '/missing')->state(), 'a root that does not exist: none');

// --- readFile(): each file runs once per request, a changed file anew --------
$runs = sudare_tmp();
$counter = $runs . '/site/config/sudare.php';
sudare_write($counter, "<?php\n\$GLOBALS['sudareRuns'] = (\$GLOBALS['sudareRuns'] ?? 0) + 1;\nreturn ['user' => 'first', 'hash' => " . var_export(sudare_hash(), true) . "];\n");
$GLOBALS['sudareRuns'] = 0;
expect('first', Config::load($runs)->user(), 'readFile: first load');
expect('first', Config::load($runs)->user(), 'readFile: second load, same result');
expect(1, $GLOBALS['sudareRuns'], 'readFile: the file ran once');
sudare_write($counter, "<?php return ['user' => 'second', 'hash' => " . var_export(sudare_hash(), true) . "];\n");
expect('second', Config::load($runs)->user(), 'readFile: a changed file is read anew');

finish('ConfigTest');
