<?php

/**
 * The facade in fresh PHP processes (SPEC.md §10.7). Every case runs
 * Sudare::guard() through sudare_php(), so exit code, http_response_code()
 * and stdout are real. header() is a no-op in the CLI: headers are checked
 * in HttpTest.php, where a real server sends them.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

use Sudare\Sudare;

const SUDARE_UNAVAILABLE = "The preview is temporarily unavailable.\n";

/** A request as $_SERVER sees it. */
function sudare_request(string $path, string $method = 'GET', array $extra = []): array
{
    return ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path] + $extra;
}

/**
 * Runs guard() for $root in a fresh process. When the request passes, the
 * probe prints "KIRBY active" or "KIRBY inactive" — the stand-in for Kirby.
 */
function sudare_run_guard(string $root, array $server, array $env = [], array $ini = []): array
{
    $code = '\Sudare\Sudare::guard(' . var_export($root, true) . ');'
        . "\necho 'KIRBY ', \\Sudare\\Sudare::active() ? 'active' : 'inactive';";
    return sudare_php($code, $server, $env, $ini);
}

$password = 'test-password';

/* --------------------------------------------------------------------------
 * No configuration
 * -------------------------------------------------------------------------- */

$none = sudare_tmp();
$r = sudare_run_guard($none, sudare_request('/'));
expect(0, $r['exit'], 'no config: the probe exits 0');
expect(200, $r['status'], 'no config: status 200');
expect('KIRBY inactive', $r['out'], 'no config: the request continues to "Kirby", Sudare inactive');

/* --------------------------------------------------------------------------
 * Valid configuration, Basic Auth
 * -------------------------------------------------------------------------- */

$on = sudare_tmp();
sudare_config($on, ['user' => 'preview', 'hash' => sudare_hash($password)]);

$r = sudare_run_guard($on, sudare_request('/'));
expect(0, $r['exit'], 'valid config, no credentials: exit 0');
expect(401, $r['status'], 'valid config, no credentials: status 401');
holds(str_contains($r['out'], 'not public yet'), 'valid config, no credentials: the 401 page is printed');
holds(!str_contains($r['out'], 'KIRBY'), 'valid config, no credentials: Kirby is not reached');

$r = sudare_run_guard($on, sudare_basic('preview', 'wrong-password', sudare_request('/')));
expect(401, $r['status'], 'valid config, wrong password: status 401');
holds(!str_contains($r['out'], 'KIRBY'), 'valid config, wrong password: Kirby is not reached');

$r = sudare_run_guard($on, sudare_basic('preview', $password, sudare_request('/')));
expect(0, $r['exit'], 'valid config, right credentials: exit 0');
expect(200, $r['status'], 'valid config, right credentials: status 200');
expect('KIRBY active', $r['out'], 'valid config, right credentials: Kirby reached, active() is true');

$r = sudare_run_guard($on, sudare_basic('Preview', $password, sudare_request('/')));
expect(401, $r['status'], 'valid config, user name is case-sensitive');

/* HEAD: status and headers only; OPTIONS: 204 without credentials */
$r = sudare_run_guard($on, sudare_request('/', 'HEAD'));
expect(401, $r['status'], 'HEAD without credentials: status 401');
expect('', $r['out'], 'HEAD without credentials: no body');

$r = sudare_run_guard($on, sudare_request('/', 'OPTIONS'));
expect(204, $r['status'], 'OPTIONS without credentials: status 204');
expect('', $r['out'], 'OPTIONS without credentials: no body');

/* Token header */
$tokenRoot = sudare_tmp();
sudare_config($tokenRoot, [
    'user'  => 'preview',
    'hash'  => sudare_hash($password),
    'token' => sudare_hash('machine-token-123'),
]);
$r = sudare_run_guard($tokenRoot, sudare_request('/', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'machine-token-123']));
expect(200, $r['status'], 'token header right: status 200');
expect('KIRBY active', $r['out'], 'token header right: Kirby reached');

$r = sudare_run_guard($tokenRoot, sudare_request('/', 'GET', ['HTTP_X_SUDARE_TOKEN' => 'wrong-token']));
expect(401, $r['status'], 'token header wrong: status 401');

/* Exempt path */
$exemptRoot = sudare_tmp();
sudare_config($exemptRoot, [
    'user'   => 'preview',
    'hash'   => sudare_hash($password),
    'exempt' => ['/api/hook'],
]);
$r = sudare_run_guard($exemptRoot, sudare_request('/api/hook'));
expect(200, $r['status'], 'exempt path without credentials: status 200');
expect('KIRBY active', $r['out'], 'exempt path without credentials: Kirby reached');

$r = sudare_run_guard($exemptRoot, sudare_request('/api/hooks'));
expect(401, $r['status'], 'a sibling of an exempt path is not exempt');

/* Media under protection */
$mediaRoot = sudare_tmp();
sudare_config($mediaRoot, ['user' => 'preview', 'hash' => sudare_hash($password)]);
sudare_write($mediaRoot . '/public/media/pages/p/img.webp', 'RIFFTEST');
sudare_write($mediaRoot . '/public/media/pages/p/upload.js', 'alert(1)');

$r = sudare_run_guard($mediaRoot, sudare_request('/media/pages/p/img.webp'));
expect(401, $r['status'], 'media file without credentials: status 401');
holds(!str_contains($r['out'], 'RIFFTEST'), 'media file without credentials: the file bytes are not printed');

$r = sudare_run_guard($mediaRoot, sudare_basic('preview', $password, sudare_request('/media/pages/p/img.webp')));
expect(200, $r['status'], 'media file with credentials: status 200');
expect('RIFFTEST', $r['out'], 'media file with credentials: the file bytes are printed, nothing else');

$r = sudare_run_guard($mediaRoot, sudare_basic('preview', $password, sudare_request('/media/pages/p/upload.js')));
expect('KIRBY active', $r['out'], 'upload.js under media/pages is not served by Sudare: it goes to Kirby');

/* Environment only: no file at all */
$envRoot = sudare_tmp();
$envVars = ['SUDARE_USER' => 'preview', 'SUDARE_HASH' => sudare_hash($password)];
$r = sudare_run_guard($envRoot, sudare_request('/'), $envVars);
expect(401, $r['status'], 'env-only configuration, no credentials: status 401');
$r = sudare_run_guard($envRoot, sudare_basic('preview', $password, sudare_request('/')), $envVars);
expect('KIRBY active', $r['out'], 'env-only configuration, right credentials: Kirby reached');

/* Legacy credentials.php: German texts, realm from the legacy default */
$legacyRoot = sudare_tmp();
sudare_config($legacyRoot, ['vorschau' => ['benutzer' => 'preview', 'hash' => sudare_hash('legacy-password')]], 'credentials.php');
$r = sudare_run_guard($legacyRoot, sudare_request('/'));
expect(401, $r['status'], 'legacy credentials.php, no credentials: status 401');
holds(str_contains($r['out'], 'Diese Seite ist noch nicht öffentlich'), 'legacy credentials.php: German 401 page');
holds(str_contains($r['out'], 'lang="de"'), 'legacy credentials.php: lang="de"');
$r = sudare_run_guard($legacyRoot, sudare_basic('preview', 'wrong', sudare_request('/')));
expect(401, $r['status'], 'legacy credentials.php, wrong password: status 401');
holds(str_contains($r['out'], 'Diese Seite ist noch nicht öffentlich'), 'legacy credentials.php, wrong password: German 401 page');
$r = sudare_run_guard($legacyRoot, sudare_basic('preview', 'legacy-password', sudare_request('/')));
expect('KIRBY active', $r['out'], 'legacy credentials.php, right credentials: Kirby reached');

/* --------------------------------------------------------------------------
 * Broken configuration: 503, fixed text, the file's output never printed
 * -------------------------------------------------------------------------- */

$brokenCases = [
    'empty file'            => '',
    'syntax error'          => '<?php return [;',
    'returns a string'      => '<?php return "x";',
    'wrong nested type'     => '<?php return ["vorschau" => "x"];',
    'plain password as hash' => '<?php return ["user" => "preview", "hash" => "plain-password"];',
    'output inside the file' => '<?php echo "LEAK"; return [];',
];
foreach ($brokenCases as $name => $content) {
    $broken = sudare_tmp();
    sudare_write($broken . '/site/config/sudare.php', $content);
    $r = sudare_run_guard($broken, sudare_basic('preview', $password, sudare_request('/')));
    expect(503, $r['status'], "broken ($name): status 503 even with credentials");
    expect(SUDARE_UNAVAILABLE, $r['out'], "broken ($name): body is the fixed sentence");
    holds(!str_contains($r['out'], 'KIRBY'), "broken ($name): Kirby is not reached");
    holds(!str_contains($r['out'], 'LEAK'), "broken ($name): output of the file is not printed");
    holds(!str_contains($r['out'], 'plain-password'), "broken ($name): no secret in the output");
}

// A file that ends the script: in a CLI the process fails with exit 2,
// and nothing the file printed reaches the output. (The web answer, a 503,
// is in HttpTest.php: header() and the status need a real server.)
$exits = sudare_tmp();
sudare_write($exits . '/site/config/sudare.php', '<?php echo "LEAK"; exit;');
$r = sudare_run_guard($exits, sudare_basic('preview', $password, sudare_request('/')));
expect(2, $r['exit'], 'exit in the file: the CLI process fails with exit 2');
holds(!str_contains($r['out'] . $r['err'], 'LEAK'), 'exit in the file: its output is discarded');
holds(!str_contains($r['out'], 'KIRBY'), 'exit in the file: Kirby is not reached');

// Round 2k: a fatal error the file cannot recover from (memory exhausted) is
// printed by PHP itself, past the output buffer, when display_errors is on —
// with the file's path and a stack trace. Not while Sudare reads the file.
$exhausts = sudare_tmp();
sudare_write($exhausts . '/site/config/sudare.php', '<?php ini_set("memory_limit", "16M"); $a = []; for (;;) { $a[] = str_repeat("x", 100000); }');
$r = sudare_run_guard($exhausts, sudare_basic('preview', $password, sudare_request('/')), [], ['display_errors' => '1']);
expect(2, $r['exit'], 'memory exhausted in the file: exit 2');
holds(!str_contains($r['out'], 'Fatal error') && !str_contains($r['out'], 'sudare.php'), 'memory exhausted in the file, display_errors on: no fatal text, no path in the output');
holds(!str_contains($r['out'], 'KIRBY'), 'memory exhausted in the file: Kirby is not reached');

// guard() and protects() read the same file in one request: a function the
// file declares must not be declared twice (a fatal error, a white page).
$declares = sudare_tmp();
sudare_write($declares . '/site/config/sudare.php', "<?php\nfunction sudare_site_theme(): array { return ['paper' => '#FCFAF8']; }\n"
    . "return ['user' => 'preview', 'hash' => " . var_export(sudare_hash($password), true) . ", 'theme' => sudare_site_theme()];\n");
$r = sudare_php(
    '\Sudare\Sudare::guard(' . var_export($declares, true) . ");\n"
    . 'echo \Sudare\Sudare::protects(' . var_export($declares, true) . ", []) ? 'PROTECTS' : 'OPEN';",
    sudare_basic('preview', $password, sudare_request('/'))
);
expect([0, 200, 'PROTECTS'], [$r['exit'], $r['status'], $r['out']], 'guard() then protects() with a function in the file: no fatal error');

/* --------------------------------------------------------------------------
 * The facade's API inside one process
 * -------------------------------------------------------------------------- */

Sudare::reset();
ob_start();
$response = Sudare::handle($on, null, sudare_request('/'), []);
$printed = (string) ob_get_clean();
expect(401, $response->status(), 'handle(): 401 without credentials');
expect('', $printed, 'handle(): prints nothing');
holds(Sudare::config() !== null, 'handle(): remembers the configuration');
expect(true, Sudare::active(), 'handle(): active() is true for a valid configuration');
Sudare::reset();
expect(null, Sudare::config(), 'reset(): forgets the configuration');
expect(false, Sudare::active(), 'reset(): active() is false again');

expect(true, Sudare::protects($on, []), 'protects(): true for a valid configuration, stateless');
expect(false, Sudare::protects($none, []), 'protects(): false without a configuration');
expect(false, Sudare::protects($envRoot, []), 'protects(): the environment argument is used, an empty one means none');
holds(Sudare::protects($envRoot, $envVars), 'protects(): SUDARE_* in the given environment count');

$translations = Sudare::translations();
expect('Vorschau', $translations['de']['sudare.realm'] ?? null, 'translations(): German realm');
expect('This site is not public yet', $translations['en']['sudare.denied.heading'] ?? null, 'translations(): English heading');

/* sudare_option() reads the loaded configuration; secrets never come back */
$r = sudare_php(
    "echo var_export(sudare_option('user', 'd'), true), '|', var_export(sudare_option('hash'), true), '|';\n"
    . '\Sudare\Sudare::guard(' . var_export($on, true) . ");\n"
    . "echo var_export(sudare_option('user'), true), '|', var_export(sudare_option('hash'), true), '|', var_export(sudare_option('token', 'x'), true);",
    sudare_basic('preview', $password, sudare_request('/'))
);
expect("'d'|NULL|'preview'|NULL|'x'", $r['out'], 'sudare_option(): default before guard(), user after guard(), hash never');

finish('Sudare facade (subprocess)');
