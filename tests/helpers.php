<?php
/**
 * Shared test helpers — written by the architect (SPEC.md §10.0).
 *
 *   require_once __DIR__ . '/helpers.php';
 *   require_once __DIR__ . '/../bootstrap.php';   // loads every src file once
 *
 * Every test file: call expect()/holds() as often as needed, then
 * finish('Suite name') at the end — it prints all failures and exits 1, or
 * prints the count and exits 0. A failing check does not stop the file, so
 * one run shows every problem.
 *
 * Three ways to exercise Sudare:
 *   - pure:        Guard::handle(), Media::respond(), Sudare::handle() → Response
 *   - subprocess:  sudare_php() runs a probe script in a fresh PHP process
 *                  (exit, http_response_code, stdout, stderr — header() is a
 *                  no-op in the CLI, so headers are NOT visible here)
 *   - HTTP:        sudare_server() starts PHP's built-in server with a router,
 *                  sudare_http() requests it — real status line and headers
 */

$GLOBALS['sudareChecks'] = 0;
$GLOBALS['sudareFailures'] = [];
$GLOBALS['sudareTemp'] = [];

/** Strict comparison ($expected === $actual). */
function expect(mixed $expected, mixed $actual, string $what): void
{
    $GLOBALS['sudareChecks']++;
    if ($expected !== $actual) {
        $GLOBALS['sudareFailures'][] = sprintf(
            "  ✗ %s\n      expected: %s\n      actual:   %s",
            $what,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

function holds(bool $ok, string $what): void
{
    $GLOBALS['sudareChecks']++;
    if (!$ok) {
        $GLOBALS['sudareFailures'][] = '  ✗ ' . $what;
    }
}

/** Only these keys of an array, in this order (for comparing parts of a Response). */
function only(array $array, array $keys): array
{
    $result = [];
    foreach ($keys as $key) {
        $result[$key] = $array[$key] ?? null;
    }
    return $result;
}

/** Prints the result, removes temporary directories and exits: 0 when every check passed, 1 otherwise. */
function finish(string $suite): never
{
    foreach ($GLOBALS['sudareTemp'] as $dir) {
        sudare_remove_tree($dir);
    }
    $failures = $GLOBALS['sudareFailures'];
    $checks = $GLOBALS['sudareChecks'];
    if ($failures !== []) {
        fwrite(STDERR, "$suite: " . count($failures) . " of $checks checks failed\n" . implode("\n", $failures) . "\n");
        exit(1);
    }
    echo "$suite: all $checks checks passed\n";
    exit(0);
}

/* --------------------------------------------------------------------------
 * Temporary project roots
 * -------------------------------------------------------------------------- */

/**
 * A fresh project root with site/config, public/media and public/assets,
 * removed by finish(). Returns the absolute path (realpath, no symlinks —
 * macOS /var → /private/var, which matters for Media's realpath checks).
 */
function sudare_tmp(): string
{
    $root = sys_get_temp_dir() . '/sudare-' . bin2hex(random_bytes(6));
    foreach (['site/config', 'public/media', 'public/assets'] as $dir) {
        mkdir($root . '/' . $dir, 0700, true);
    }
    $root = realpath($root);
    $GLOBALS['sudareTemp'][] = $root;
    return $root;
}

/** Writes a file, creating parent directories. */
function sudare_write(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($path, $content);
}

/** A sudare.php (or any config file) returning this array, written into $root/site/config/$name. */
function sudare_config(string $root, array $config, string $name = 'sudare.php'): string
{
    $file = $root . '/site/config/' . $name;
    sudare_write($file, '<?php return ' . var_export($config, true) . ';');
    return $file;
}

function sudare_remove_tree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            sudare_remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** A valid bcrypt hash of $password (cost 4: fast, still a real hash). */
function sudare_hash(string $password = 'test-password'): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
}

/** $_SERVER for a Basic Auth request. */
function sudare_basic(string $user, string $password, array $server = []): array
{
    return ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($user . ':' . $password)] + $server;
}

/* --------------------------------------------------------------------------
 * Subprocess: a probe script in a fresh PHP, with bootstrap.php loaded
 * -------------------------------------------------------------------------- */

/**
 * Runs $code (PHP without the opening tag) in a new PHP process after
 * bootstrap.php, with $_SERVER replaced by $server and the given environment
 * (SUDARE_* keys are what matters; PATH is kept). The probe prints
 * "STATUS=<http_response_code>" to STDERR at shutdown.
 *
 * $ini lays php.ini settings over the probe's defaults (display_errors=stderr).
 *
 * @return array{exit: int, out: string, err: string, status: int}
 */
function sudare_php(string $code, array $server = [], array $env = [], array $ini = []): array
{
    $probe = tempnam(sys_get_temp_dir(), 'sudare-probe-') . '.php';
    $script = '<?php register_shutdown_function(static function () { fwrite(STDERR, "\nSTATUS=" . (http_response_code() ?: 200) . "\n"); });'
        . '$_SERVER = ' . var_export($server, true) . ';'
        . 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
        . $code;
    file_put_contents($probe, $script);
    $environment = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $env;
    $flags = [];
    foreach ($ini + ['display_errors' => 'stderr'] as $key => $value) {
        array_push($flags, '-d', $key . '=' . $value);
    }
    $process = proc_open(
        [PHP_BINARY, ...$flags, $probe],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    @unlink($probe);
    preg_match('/STATUS=(\d+)/', $err, $m);
    return ['exit' => $exit, 'out' => $out, 'err' => $err, 'status' => (int) ($m[1] ?? 0)];
}

/* --------------------------------------------------------------------------
 * HTTP: PHP's built-in server
 * -------------------------------------------------------------------------- */

function sudare_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("cannot find a free port: $error");
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * Starts `php -S 127.0.0.1:<port> -t $docroot $router` with the given
 * environment variables and waits until it answers. Stop it with
 * sudare_server_stop(). The router is a PHP file that calls
 * Sudare\Sudare::guard() and prints something recognisable.
 *
 * @return array{process: resource, port: int, pipes: array, log: string}
 */
function sudare_server(string $docroot, string $router, array $env = []): array
{
    $port = sudare_free_port();
    $environment = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $env;
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-S', "127.0.0.1:$port", '-t', $docroot, $router],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $docroot,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start the built-in server');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return ['process' => $process, 'port' => $port, 'pipes' => $pipes, 'log' => ''];
        }
        usleep(50_000);
    }
    $log = stream_get_contents($pipes[2]) . stream_get_contents($pipes[1]);
    proc_terminate($process);
    throw new RuntimeException("built-in server did not start on port $port: $log");
}

/** Stops the server and returns what it wrote to STDERR/STDOUT (for diagnostics). */
function sudare_server_stop(array $server): string
{
    $log = (string) stream_get_contents($server['pipes'][2]) . (string) stream_get_contents($server['pipes'][1]);
    fclose($server['pipes'][1]);
    fclose($server['pipes'][2]);
    proc_terminate($server['process']);
    proc_close($server['process']);
    return $log;
}

/**
 * One request against the built-in server, no redirects followed.
 * Header names come back lower-cased; a header sent twice is joined with ", ".
 *
 * @param array<string,string> $headers e.g. ['Authorization' => 'Basic …']
 * @return array{status: int, headers: array<string,string>, body: string}
 */
function sudare_http(int $port, string $method, string $path, array $headers = []): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    $context = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $lines),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 10,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $context);
    $raw = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $raw[0] ?? '', $m);
    $parsed = [];
    foreach (array_slice($raw, 1) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $parsed[$name] = isset($parsed[$name]) ? $parsed[$name] . ', ' . $value : $value;
    }
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $parsed, 'body' => (string) $body];
}
