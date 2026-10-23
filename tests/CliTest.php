<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Cli;
use Sudare\I18n;

// SPEC.md §10.6: Cli::hash, Cli::check, Cli::arguments, and the bin/ wrappers.

/** A memory stream holding $content, rewound. */
function cliStream(string $content = ''): mixed
{
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $content);
    rewind($stream);
    return $stream;
}

function cliRead(mixed $stream): string
{
    rewind($stream);
    return (string) stream_get_contents($stream);
}

/** Runs sudare-hash in-process: [exit, stdout, stderr]. */
function cliHash(array $args, string $input, array $env = []): array
{
    $out = cliStream();
    $err = cliStream();
    $code = Cli::hash(array_merge(['sudare-hash'], $args), cliStream($input), $out, $err, $env);
    return [$code, cliRead($out), cliRead($err)];
}

/** Runs sudare-check in-process: [exit, stdout, stderr]. */
function cliCheck(array $args, array $env = [], string $cwd = '/'): array
{
    $out = cliStream();
    $err = cliStream();
    $code = Cli::check(array_merge(['sudare-check'], $args), $out, $err, $env, $cwd);
    return [$code, cliRead($out), cliRead($err)];
}

/** Writes a config file into a fresh root and returns the root. */
function cliRoot(string $file, string $content): string
{
    $root = sudare_tmp();
    sudare_write($root . '/site/config/' . $file, $content);
    return $root;
}

/**
 * Loads a PHP config returned by sudare-hash. Every call writes to a new file,
 * so no earlier include can be served from a cache.
 */
function cliLoad(string $php): mixed
{
    static $n = 0;
    $file = sudare_tmp() . '/generated-' . (++$n) . '.php';
    file_put_contents($file, $php);
    return require $file;
}

// --- arguments() -----------------------------------------------------------
expect(
    ['a' => '1', 'b' => true, '_' => ['x', 'y']],
    Cli::arguments(['bin', '--a=1', '--b', 'x', 'y']),
    'arguments: --key=value, flag and positionals'
);
expect(['_' => []], Cli::arguments(['bin']), 'arguments: nothing given');
expect(['user' => 'a=b', '_' => []], Cli::arguments(['bin', '--user=a=b']), 'arguments: value may contain =');

// --- wrappers are executable -----------------------------------------------
holds(is_executable(dirname(__DIR__) . '/bin/sudare-hash'), 'bin/sudare-hash is executable');
holds(is_executable(dirname(__DIR__) . '/bin/sudare-check'), 'bin/sudare-check is executable');

// --- hash -----------------------------------------------------------------
$pw = 'correct horse';
[$code, $out, $err] = cliHash(['--user=preview'], $pw . "\n");
expect(Cli::EXIT_OK, $code, 'hash: pipe input exits 0');
holds(str_starts_with($out, "<?php\n\nreturn [\n"), 'hash: output starts with the config file header');
$config = cliLoad($out);
expect('preview', $config['user'] ?? null, 'hash: default user is preview');
holds(password_verify($pw, $config['hash'] ?? ''), 'hash: the hash verifies the piped password');
holds(!str_contains($out . $err, $pw), 'hash: the plain password appears in neither stream');
holds(!isset($config['realm']) && !isset($config['token']), 'hash: no realm or token without the flags');
holds(str_contains($err, 'Content for the secret SUDARE_PHP'), 'hash: info names the SUDARE_PHP secret');

// Bcrypt cost 10 by default (PHP's own default is 12 since 8.4 —
// ~250 ms per authenticated file request on a shared host), --cost changes it.
holds(str_starts_with($config['hash'] ?? '', '$2y$10$'), 'hash: bcrypt with cost 10 by default');
[$code, $out] = cliHash(['--cost=4'], $pw . "\n");
holds($code === Cli::EXIT_OK && str_starts_with(cliLoad($out)['hash'] ?? '', '$2y$04$'), 'hash: --cost=4 honoured');
[$code, $out] = cliHash(['--cost=12', '--token'], $pw . "\n");
$costly = cliLoad($out);
holds($code === Cli::EXIT_OK && str_starts_with($costly['hash'] ?? '', '$2y$12$') && str_starts_with($costly['token'] ?? '', '$2y$12$'), 'hash: --cost applies to the token hash too');
foreach (['--cost=3', '--cost=32', '--cost=abc', '--cost=', '--cost'] as $bad) {
    expect(Cli::EXIT_USAGE, cliHash([$bad], $pw . "\n")[0], "hash: $bad is a usage error");
}

[$code, $out] = cliHash(['--user=client', '--realm=Vorschau X'], $pw . "\n");
$config = cliLoad($out);
expect(['client', 'Vorschau X'], [$config['user'], $config['realm']], 'hash: --user and --realm honoured');

[$code, $out, $err] = cliHash(['--user=ci', '--token'], $pw . "\n");
preg_match('/\b([0-9a-f]{48})\b/', $err, $match);
holds(isset($match[1]), 'hash: --token prints a 48-hex token on stderr');
$config = cliLoad($out);
holds(isset($match[1]) && password_verify($match[1], $config['token'] ?? ''), 'hash: the token hash verifies the printed token');
holds(!str_contains($out, $match[1] ?? '-'), 'hash: the plain token never reaches the config file');
holds(!str_contains($out . $err, $pw), 'hash: --token run never shows the password');

[$code, $out, $err] = cliHash(['--legacy'], $pw . "\n");
holds(str_starts_with($out, "<?php\n\nreturn ['vorschau' => ['benutzer' => 'preview', 'hash' =>"), 'hash: --legacy writes the credentials.php format');
holds(str_contains($err, 'Content for the secret CREDENTIALS_PHP'), 'hash: --legacy names the CREDENTIALS_PHP secret');
$legacy = cliLoad($out);
holds(password_verify($pw, $legacy['vorschau']['hash'] ?? ''), 'hash: legacy hash verifies');

[$code, $out] = cliHash(['--legacy', '--realm=Vorschau Y'], $pw . "\n");
$legacy = cliLoad($out);
expect('Vorschau Y', $legacy['vorschau']['realm'] ?? null, 'hash: --legacy honours --realm');

expect(Cli::EXIT_USAGE, cliHash(['--legacy', '--token'], $pw . "\n")[0], 'hash: --legacy with --token is a usage error');
expect(Cli::EXIT_USAGE, cliHash(['--bogus'], $pw . "\n")[0], 'hash: unknown option exits 3');
expect(Cli::EXIT_USAGE, cliHash(['extra'], $pw . "\n")[0], 'hash: positional argument exits 3');
expect(Cli::EXIT_USAGE, cliHash(['--user=a:b'], $pw . "\n")[0], 'hash: a colon in the user is a usage error');
expect(Cli::EXIT_USAGE, cliHash(['--user='], $pw . "\n")[0], 'hash: an empty user is a usage error');
// bcrypt reads 72 bytes: a longer password would only count up to there.
[$code, $out, $err] = cliHash([], str_repeat('a', 73) . "\n");
expect([Cli::EXIT_FAIL, ''], [$code, $out], 'hash: 73 bytes are refused (bcrypt ignores the rest)');
holds(str_contains($err, 'longer than 72 bytes'), 'hash: says why');
expect(Cli::EXIT_FAIL, cliHash([], str_repeat('ä', 37) . "\n")[0], 'hash: 37 umlauts are 74 bytes, refused');
expect(Cli::EXIT_OK, cliHash([], str_repeat('a', 72) . "\n")[0], 'hash: exactly 72 bytes are fine');
expect(Cli::EXIT_FAIL, cliHash([], "pass\0word-long\n")[0], 'hash: a NUL byte is refused, not a crash');
[$code, $out] = cliHash(['--user'], $pw . "\n");
expect([Cli::EXIT_USAGE, ''], [$code, $out], 'hash: --user without a value is a usage error, not user "1"');
expect(Cli::EXIT_USAGE, cliHash(['--realm'], $pw . "\n")[0], 'hash: --realm without a value is a usage error');
expect(Cli::EXIT_USAGE, cliHash(['--language=fr'], $pw . "\n")[0], 'hash: unsupported language exits 3');

[$code, $out, $err] = cliHash([], "\n");
expect(Cli::EXIT_FAIL, $code, 'hash: empty input exits 1');
holds(str_contains($err, I18n::t('cli.hash.empty')) && str_contains($err, 'No password entered'), 'hash: empty input message');
expect('', $out, 'hash: nothing written to stdout on failure');

[$code, $out, $err] = cliHash([], "short\n");
expect(Cli::EXIT_FAIL, $code, 'hash: short password exits 1');
holds(str_contains($err, 'shorter than 8'), 'hash: short password message');
expect('', $out, 'hash: nothing written to stdout for a short password');

[$code, , $err] = cliHash(['--language=de'], "\n");
holds(str_contains($err, 'Passwort für die Vorschau'), 'hash: --language=de prints the German prompt');

[$code, , $err] = cliHash([], "\n", ['SUDARE_LANGUAGE' => 'de']);
holds(str_contains($err, 'Passwort für die Vorschau'), 'hash: SUDARE_LANGUAGE=de is the fallback language');

[$code, $out] = cliHash(['--help'], '');
expect(Cli::EXIT_OK, $code, 'hash: --help exits 0');
holds(str_contains($out, 'Usage: sudare-hash'), 'hash: --help prints usage on stdout');

$tricky = 'pa$$w"ord\'\\ß€';
[$code, $out] = cliHash(['--user=preview'], $tricky . "\n");
$config = cliLoad($out);
holds($code === 0 && password_verify($tricky, $config['hash'] ?? ''), 'hash: $, quotes, backslash and umlauts roundtrip');

// --- check ----------------------------------------------------------------
/** Runs a bin/ script in a real PHP process: [exit, stdout, stderr]. */
$runBin = function (string $script, array $args, string $stdin = ''): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
};

$empty = sudare_tmp();
[$code, $out] = cliCheck([$empty]);
expect(Cli::EXIT_FAIL, $code, 'check: empty root is not protected (exit 1)');
holds(str_contains($out, $empty), 'check: the root path is printed');
[$code, $out] = cliCheck([$empty, '--allow-off']);
expect(Cli::EXIT_OK, $code, 'check: --allow-off accepts an unprotected root');
holds(str_contains($out, 'accepted because of --allow-off'), 'check: --allow-off explains itself');

$valid = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => " . var_export(sudare_hash(), true) . "];");
[$code, $out] = cliCheck([$valid]);
expect(Cli::EXIT_OK, $code, 'check: valid sudare.php exits 0');
holds(str_contains($out, 'sudare.php') && str_contains($out, 'test') && str_contains($out, 'bcrypt') && str_contains($out, 'Preview'), 'check: ok line names source, user, algorithm, realm');
holds(str_contains($out, 'media/ yes, assets/ yes'), 'check: files line');
holds(!str_contains($out, '$2y$'), 'check: the hash never printed');

$withToken = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => " . var_export(sudare_hash(), true) . ", 'token' => " . var_export(sudare_hash('t'), true) . ", 'exempt' => ['/api/hook'], 'media' => false];");
[$code, $out] = cliCheck([$withToken]);
holds(str_contains($out, 'Machine token: configured') && str_contains($out, 'Exempt paths: /api/hook'), 'check: token and exempt lines');
holds(str_contains($out, 'media/ no'), 'check: media no');
holds(!str_contains($out, '$2y$'), 'check: token hash never printed');

$legacyRoot = cliRoot('credentials.php', "<?php return ['vorschau' => ['benutzer' => 'test', 'hash' => " . var_export(sudare_hash(), true) . "]];");
[$code, $out] = cliCheck([$legacyRoot]);
holds(str_contains($out, 'credentials.php') && str_contains($out, 'Sudare-Konfiguration gültig'), 'check: legacy file speaks German');
[$code, $out] = cliCheck([$legacyRoot, '--language=en']);
holds(str_contains($out, 'configuration valid') && str_contains($out, 'credentials.php'), 'check: --language=en overrides the legacy default');

$plain = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => 'plain-password-here'];");
[$code, $out, $err] = cliCheck([$plain]);
expect(Cli::EXIT_BROKEN, $code, 'check: broken configuration exits 2');
holds(str_contains($out, 'complete password hash'), 'check: broken reason text');
holds(!str_contains($out . $err, 'plain-password-here') && !str_contains($out . $err, '$2y$'), 'check: no secret in broken output');

// A damaged $2y$ hash (cost 99) has the right prefix and length, so
// password_get_info() takes it: the gate must still stop it.
$cost99 = substr_replace(sudare_hash(), '99', 4, 2);
$damagedHash = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => " . var_export($cost99, true) . "];");
[$code, $out, $err] = cliCheck([$damagedHash]);
expect(Cli::EXIT_BROKEN, $code, 'check: a $2y$ hash with cost 99 exits 2');
holds(str_contains($out, '"hash" is not a complete password hash'), 'check: damaged hash names the reason hash');
holds(!str_contains($out . $err, $cost99), 'check: damaged hash never printed');
$damagedToken = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => " . var_export(sudare_hash(), true) . ", 'token' => " . var_export($cost99, true) . "];");
[$code, $out, $err] = cliCheck([$damagedToken]);
expect(Cli::EXIT_BROKEN, $code, 'check: a $2y$ token with cost 99 exits 2');
holds(str_contains($out, '"token" is set but'), 'check: damaged token names the reason token');
holds(!str_contains($out . $err, $cost99), 'check: damaged token never printed');

[$code, $out] = cliCheck([$plain], ['GITHUB_ACTIONS' => 'true']);
holds(str_starts_with($out, '::error::') && $code === Cli::EXIT_BROKEN, 'check: GITHUB_ACTIONS annotates a broken configuration');

$inactive = cliRoot('sudare.php', "<?php return ['active' => false, 'user' => 'test'];");
expect(Cli::EXIT_FAIL, cliCheck([$inactive])[0], 'check: active => false exits 1');
expect(Cli::EXIT_OK, cliCheck([$inactive, '--allow-off'])[0], 'check: active => false with --allow-off exits 0');
[$code, $out] = cliCheck([$inactive, '--allow-off'], ['GITHUB_ACTIONS' => 'true']);
holds(!str_contains($out, '::error::') && str_contains($out, 'accepted because of --allow-off'), 'check: --allow-off is not annotated as an error');
[$code, $out] = cliCheck([$inactive], ['GITHUB_ACTIONS' => 'true']);
holds(str_starts_with($out, '::error::'), 'check: switched-off configuration is annotated without --allow-off');

// The gate checks the files: the SUDARE_* of a CI shell are not the server's.
$envVars = ['SUDARE_USER' => 'env', 'SUDARE_HASH' => sudare_hash()];
expect(Cli::EXIT_FAIL, cliCheck([], $envVars, $empty)[0], 'check: SUDARE_* of the shell do not count without --env');
[$code, $out] = cliCheck(['--env'], $envVars, $empty);
expect(Cli::EXIT_OK, $code, 'check --env: SUDARE_* alone is a configuration');
holds(str_contains($out, 'environment'), 'check --env: source environment');
holds(str_contains($out, 'Counted from the environment (--env): SUDARE_USER, SUDARE_HASH.'), 'check --env: names the counted variables');
$parked = cliRoot('sudare.php', "<?php return ['active' => false, 'user' => 'test', 'hash' => " . var_export(sudare_hash(), true) . "];");
expect(Cli::EXIT_FAIL, cliCheck([$parked], ['SUDARE_ACTIVE' => 'true'])[0], 'check: SUDARE_ACTIVE=true of the shell does not hide a parked file');
[$code, $out] = cliCheck([$parked, '--env'], ['SUDARE_ACTIVE' => 'true', 'GITHUB_ACTIONS' => 'true']);
holds($code === Cli::EXIT_OK && str_contains($out, '(--env): SUDARE_ACTIVE.'), 'check --env: the overriding variable is named');
expect(Cli::EXIT_USAGE, cliCheck(['--env=yes'])[0], 'check: --env takes no value');

expect(Cli::EXIT_OK, cliCheck([$valid . '/site/config/sudare.php'])[0], 'check: a file path instead of a root');
expect(Cli::EXIT_OK, cliCheck(['site/config/sudare.php'], [], $valid)[0], 'check: relative path resolved against cwd');

[$code, $out] = cliCheck([$empty], ['GITHUB_ACTIONS' => 'true']);
holds(str_starts_with($out, '::error::'), 'check: GITHUB_ACTIONS prefixes failing lines');
// The counted-variables line is information, not the verdict.
[$code, $out] = cliCheck([$plain, '--env'], ['SUDARE_REALM' => 'R', 'GITHUB_ACTIONS' => 'true']);
$outLines = explode("\n", trim($out));
holds($code === Cli::EXIT_BROKEN && str_starts_with($outLines[0], 'Counted from the environment') && str_starts_with($outLines[1] ?? '', '::error::'),
    'check: under GITHUB_ACTIONS only the verdict line is annotated, not the --env note');
[$code, $out] = cliCheck([$valid], ['GITHUB_ACTIONS' => 'true']);
holds(!str_contains($out, '::error::'), 'check: no annotation on success');

// A path that does not exist holds no configuration: "none", not "broken".
foreach (['missing file' => $empty . '/site/config/sudare.php', 'missing root' => $empty . '/nowhere'] as $what => $missing) {
    [$code, $out] = cliCheck([$missing]);
    expect(Cli::EXIT_FAIL, $code, "check: $what exits 1");
    holds(str_contains($out, 'No Sudare configuration at ' . $missing), "check: $what is reported as none");
    expect(Cli::EXIT_OK, cliCheck([$missing, '--allow-off'])[0], "check: $what with --allow-off exits 0 (public deploy)");
}
symlink($empty . '/gone.php', $empty . '/dangling.php');
expect(Cli::EXIT_BROKEN, cliCheck([$empty . '/dangling.php', '--allow-off'])[0], 'check: a dangling symlink is still broken');

// SUDARE_LANGUAGE picks the CLI's language; alone it configures nothing.
[$code, $out] = cliCheck([$empty, '--allow-off'], ['SUDARE_LANGUAGE' => 'de']);
expect(Cli::EXIT_OK, $code, 'check: SUDARE_LANGUAGE alone is no configuration (--allow-off exits 0)');
holds(str_contains($out, 'Keine Sudare-Konfiguration'), 'check: SUDARE_LANGUAGE=de speaks German without a configuration');

// The configuration's values reach the terminal and the CI log without control characters.
$escapes = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => " . var_export(sudare_hash(), true)
    . ", 'realm' => \"Pre\\nview\\e[31m\\u{9B}2J\", 'exempt' => ['/api/a']];");
[$code, $out] = cliCheck([$escapes]);
expect(Cli::EXIT_OK, $code, 'check: realm with control characters is valid');
holds(!preg_match('/[\x00-\x09\x0B-\x1F\x7F]|\x{9B}/u', $out), 'check: no control character in the output');
holds(str_contains($out, 'realm "Preview[31m2J"'), 'check: realm printed without its control characters');
// An exempt entry with a control character is broken (letters, digits, "-_./" only).
$escapes = cliRoot('sudare.php', "<?php return ['user' => 'test', 'hash' => " . var_export(sudare_hash(), true)
    . ", 'exempt' => [\"/api/a\\u{9B}2Jx\", \"/b\\n::error::x\"]];");
[$code, $out] = cliCheck([$escapes], ['GITHUB_ACTIONS' => 'true']);
expect(Cli::EXIT_BROKEN, $code, 'check: exempt entry with a control character is broken');
holds(!preg_match('/[\x00-\x09\x0B-\x1F\x7F]|\x{9B}/u', $out), 'check: broken exempt entry: no control character in the output');
holds(preg_match_all('/^::error::/m', $out) <= 1 && !str_contains($out, "\n::error::x"), 'check: an exempt path cannot start an annotation line');

// The 401 page's style needs a CSP hash on sites with style-src 'self'.
[$code, $out] = cliCheck([$valid]);
$source = Sudare\Page::styleSource(Sudare\Config::load($valid));
holds(str_contains($out, 'add ' . $source . ' to style-src'), 'check: prints the CSP source of the 401 style');

// A file that ends the script fails closed: exit 2, nothing it printed leaks.
$exits = cliRoot('sudare.php', '<?php echo "LEAK"; exit;');
[$code, $out, $stderr] = $runBin('sudare-check', [$exits]);
expect(2, $code, 'bin/sudare-check: a file that calls exit is broken (exit 2)');
holds(!str_contains($out . $stderr, 'LEAK'), 'bin/sudare-check: output of an exiting file does not leak');
holds(str_contains($stderr, 'ends the script'), 'bin/sudare-check: says why');

// Under opcache.restrict_api Sudare's opcache_invalidate() is
// refused and an old copy of the file may hold; the check says so and names
// the blacklist, without failing.
$withIni = static function (string $root, array $flags): array {
    $process = proc_open(
        array_merge([PHP_BINARY], $flags, [dirname(__DIR__) . '/bin/sudare-check', $root]),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout];
};
[$code, $out] = $withIni($valid, ['-d', 'opcache.enable_cli=1', '-d', 'opcache.restrict_api=/nowhere']);
expect(0, $code, 'bin/sudare-check under opcache.restrict_api: still exit 0');
holds(str_contains($out, 'opcache.blacklist_filename'), 'bin/sudare-check under opcache.restrict_api: names the blacklist');
[$code, $out] = $withIni($valid, ['-d', 'opcache.restrict_api=']);
expect(0, $code, 'bin/sudare-check without restrict_api: exit 0');
holds(!str_contains($out, 'opcache'), 'bin/sudare-check without restrict_api: no opcache line');

expect(Cli::EXIT_USAGE, cliCheck([$valid, $valid])[0], 'check: two positionals exit 3');
expect(Cli::EXIT_USAGE, cliCheck(['--bogus'])[0], 'check: unknown option exits 3');
expect(Cli::EXIT_USAGE, cliCheck(['--language=fr'])[0], 'check: unsupported language exits 3');
expect(Cli::EXIT_OK, cliCheck(['--help'])[0], 'check: --help exits 0');

// --- bin/ wrappers ---------------------------------------------------------

[$code] = $runBin('sudare-check', [sudare_tmp()]);
expect(1, $code, 'bin/sudare-check exits 1 in an empty root');
[$code, $out] = $runBin('sudare-hash', ['--user=ci'], "ci-only-password\n");
expect(0, $code, 'bin/sudare-hash exits 0 with a piped password');
holds(str_starts_with($out, "<?php\n\nreturn ["), 'bin/sudare-hash writes a configuration file to stdout');
$binConfig = cliLoad($out);
holds(
    password_verify('ci-only-password', $binConfig['hash'] ?? '') && ($binConfig['user'] ?? null) === 'ci',
    'bin/sudare-hash output verifies the piped password for user ci'
);
[$code, , $stderr] = $runBin('sudare-hash', ['--bogus']);
expect(3, $code, 'bin/sudare-hash exits 3 on an unknown option');
holds(str_contains($stderr, 'Usage: sudare-hash'), 'bin/sudare-hash prints usage on stderr for a usage error');

// At a terminal with shell_exec disabled (shared hosts) sudare-hash
// warns instead of dying with "Call to undefined function shell_exec()".
// Needs a pty: BSD script(1) (macOS); elsewhere visibly skipped.
if (PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/script')) {
    $process = proc_open(['/usr/bin/script', '-q', '/dev/null', PHP_BINARY, '-d', 'disable_functions=shell_exec', dirname(__DIR__) . '/bin/sudare-hash', '--user=preview'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], "geheim\n");
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    holds($code !== 255 && !str_contains($out, 'Fatal'), 'hash at a terminal without shell_exec: no fatal error');
    holds(str_contains($out, 'shell_exec is disabled'), 'hash at a terminal without shell_exec: warns that the password is visible');
} else {
    holds(true, 'hash at a terminal without shell_exec: skipped (needs BSD script)');
}

finish('CliTest');
