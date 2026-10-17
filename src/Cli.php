<?php

namespace Sudare;

/**
 * The two command-line tools behind bin/sudare-hash and bin/sudare-check,
 * as testable functions (SPEC.md §3.8, §9): streams and the environment are
 * parameters, exit codes are return values. Nothing here prints a password,
 * a hash or a token to a log; the generated file goes to $out, everything
 * else to $err (hash) or $out (check, which only ever prints states).
 */
final class Cli
{
    public const EXIT_OK = 0;

    /** hash: bad input (empty, short, roundtrip failed) — check: not protected (none or off). */
    public const EXIT_FAIL = 1;

    /** check: the configuration is broken (every request would be 503). */
    public const EXIT_BROKEN = 2;

    public const EXIT_USAGE = 3;

    public const MIN_PASSWORD_LENGTH = 8;

    /** bcrypt's limit: bytes beyond it do not count. */
    public const MAX_PASSWORD_BYTES = 72;

    public const DEFAULT_USER = 'preview';

    private const HASH_OPTIONS = ['user', 'realm', 'legacy', 'token', 'cost', 'language', 'help'];

    /**
     * bcrypt cost of the password and token hashes. PHP's own default rose
     * to 12 with 8.4; here every authenticated request — each image, each
     * stylesheet served through PHP — pays one verify, and on a shared host
     * cost 12 is ~250 ms of CPU per file against ~60–85 ms at 10 (measured
     * on Apache+FPM, round 2k). 10 is bcrypt's long-standing recommendation
     * for interactive logins; `--cost` raises it for a site that wants more.
     */
    public const DEFAULT_COST = 10;
    public const MIN_COST = 4;
    public const MAX_COST = 31;

    private const CHECK_OPTIONS = ['allow-off', 'env', 'language', 'help'];

    /**
     * sudare-hash: reads the password from $in (hidden on a terminal), writes
     * a complete configuration file to $out.
     *
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public static function hash(array $argv, $in, $out, $err, array $env = []): int
    {
        $args = self::arguments($argv);
        $help = isset($args['help']) || in_array('-h', $argv, true);
        $language = self::languageOption($args);
        if ($language === null) {
            $fromEnv = strtolower(trim((string) Options::env($env, 'language', '')));
            $language = in_array($fromEnv, I18n::LANGUAGES, true) ? $fromEnv : null;
        }
        self::setLanguage($language);

        if ($help) {
            fwrite($out, I18n::t('cli.hash.usage') . "\n");
            return self::EXIT_OK;
        }

        $unknown = array_diff(array_keys($args), self::HASH_OPTIONS, ['_']);
        $positionals = $args['_'] ?? [];
        if ($unknown !== [] || $positionals !== [] || $language === false) {
            fwrite($err, I18n::t('cli.hash.usage') . "\n");
            return self::EXIT_USAGE;
        }

        // A bare --user or --realm (no "=VALUE") arrives as true: a usage error.
        $user = $args['user'] ?? self::DEFAULT_USER;
        $realm = $args['realm'] ?? null;
        $legacy = isset($args['legacy']);
        $token = isset($args['token']);
        if (!is_string($user) || !self::validUser($user) || ($realm !== null && (!is_string($realm) || trim($realm) === ''))) {
            fwrite($err, I18n::t('cli.hash.usage') . "\n");
            return self::EXIT_USAGE;
        }
        $user = trim($user);
        if ($legacy && $token) {
            fwrite($err, I18n::t('cli.hash.usage') . "\n");
            return self::EXIT_USAGE;
        }
        // --cost=N: digits only, within bcrypt's range; a bare --cost is true.
        $cost = $args['cost'] ?? (string) self::DEFAULT_COST;
        if (!is_string($cost) || preg_match('/^\d{1,2}$/', trim($cost)) !== 1
            || (int) $cost < self::MIN_COST || (int) $cost > self::MAX_COST) {
            fwrite($err, I18n::t('cli.hash.usage') . "\n");
            return self::EXIT_USAGE;
        }
        $cost = (int) $cost;

        $password = self::readPassword($in, $err);
        if ($password === '') {
            fwrite($err, I18n::t('cli.hash.empty') . "\n");
            return self::EXIT_FAIL;
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            fwrite($err, I18n::t('cli.hash.short', ['min' => self::MIN_PASSWORD_LENGTH]) . "\n");
            return self::EXIT_FAIL;
        }
        // bcrypt reads 72 bytes and ignores the rest silently; a NUL byte
        // makes password_hash() throw.
        if (strlen($password) > self::MAX_PASSWORD_BYTES || str_contains($password, "\0")) {
            fwrite($err, I18n::t('cli.hash.long', ['max' => self::MAX_PASSWORD_BYTES]) . "\n");
            return self::EXIT_FAIL;
        }

        // bcrypt explicitly, with the cost above: PASSWORD_DEFAULT may change
        // algorithm or cost with a PHP release, and Config accepts bcrypt
        // and Argon2 hashes alike, so a site can still paste an Argon2 one.
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
        if (!password_verify($password, $hash)) {
            fwrite($err, I18n::t('cli.hash.mismatch') . "\n");
            return self::EXIT_FAIL;
        }
        $info = password_get_info($hash);
        $options = $info['options'] ?? [];
        fwrite($err, I18n::t('cli.hash.info', [
            'algo' => $info['algoName'] ?? 'unknown',
            'cost' => $options['cost'] ?? '—',
        ]) . "\n\n");

        $tokenHash = null;
        if ($token) {
            $plainToken = bin2hex(random_bytes(24));
            $tokenHash = password_hash($plainToken, PASSWORD_BCRYPT, ['cost' => $cost]);
            fwrite($err, I18n::t('cli.hash.token', ['token' => $plainToken]) . "\n\n");
        }

        fwrite($err, I18n::t('cli.hash.output', [
            'secret' => $legacy ? 'CREDENTIALS_PHP' : 'SUDARE_PHP',
        ]) . "\n\n");

        fwrite($out, self::configFile($user, $hash, $realm, $tokenHash, $legacy));
        return self::EXIT_OK;
    }

    /**
     * sudare-check: loads the configuration of a project root (or one file)
     * and reports its state; the exit code is the deploy gate.
     *
     * @param resource $out
     * @param resource $err
     */
    public static function check(array $argv, $out, $err, array $env, string $cwd): int
    {
        $args = self::arguments($argv);
        if (isset($args['help']) || in_array('-h', $argv, true)) {
            self::setLanguage(self::languageOption($args));
            fwrite($out, I18n::t('cli.check.usage') . "\n");
            return self::EXIT_OK;
        }

        $unknown = array_diff(array_keys($args), self::CHECK_OPTIONS, ['_']);
        $positionals = $args['_'] ?? [];
        $language = self::languageOption($args);
        $flagBad = (isset($args['allow-off']) && $args['allow-off'] !== true) || (isset($args['env']) && $args['env'] !== true);
        if ($unknown !== [] || count($positionals) > 1 || $language === false || $flagBad) {
            fwrite($err, I18n::t('cli.check.usage') . "\n");
            return self::EXIT_USAGE;
        }

        $given = $positionals[0] ?? null;
        $target = $given ?? $cwd;
        $path = self::absolute($target, $cwd);
        // The deploy gate checks what the server will read: the files. The
        // SUDARE_* variables of a CI shell are not the server's; only --env
        // counts them, and the output names them.
        $configEnv = isset($args['env']) ? $env : [];
        $counted = [];
        foreach (Options::ENV_KEYS as $key) {
            if ($configEnv !== [] && Options::env($configEnv, $key) !== null) {
                $counted[] = Options::envKey($key);
            }
        }
        // A directory is a project root; anything else that exists (a dangling
        // symlink too) is a configuration file. A path that does not exist
        // holds no configuration: state none, as an empty root.
        $config = is_dir($path) || (!file_exists($path) && !is_link($path))
            ? Config::load($path, $configEnv)
            : Config::fromFile($path, $configEnv);

        // Explicit --language wins; otherwise the configuration decides (a
        // legacy credentials.php speaks German), SUDARE_LANGUAGE for no config.
        if ($language !== null) {
            $lang = $language;
        } elseif ($config->state() === Config::STATE_NONE) {
            $lang = strtolower(trim((string) Options::env($env, 'language', 'en')));
        } else {
            $lang = $config->language();
        }
        self::setLanguage(in_array($lang, I18n::LANGUAGES, true) ? $lang : 'en');

        $allowOff = isset($args['allow-off']);
        $github = ($env['GITHUB_ACTIONS'] ?? null) === 'true';
        // $lines carry the verdict and get the ::error:: annotation when it
        // fails; $notes (counted variables, opcache) are information and never do.
        $lines = [];
        $notes = [];
        $code = self::EXIT_OK;
        if ($counted !== []) {
            $notes[] = I18n::t('cli.check.env', ['keys' => implode(', ', $counted)]);
        }

        switch ($config->state()) {
            case Config::STATE_NONE:
                $lines[] = I18n::t('cli.check.none', ['path' => $given ?? $target]);
                $code = self::EXIT_FAIL;
                break;
            case Config::STATE_OFF:
                $lines[] = I18n::t('cli.check.off');
                $code = self::EXIT_FAIL;
                break;
            case Config::STATE_BROKEN:
                $lines[] = I18n::t('cli.check.broken', [
                    'reason' => I18n::t('reason.' . $config->reason()),
                ]);
                $code = self::EXIT_BROKEN;
                break;
            default:
                $lines[] = I18n::t('cli.check.ok', [
                    'source' => $config->source(),
                    'user' => $config->user(),
                    'algo' => $config->algorithm(),
                    'realm' => $config->realm(),
                ]);
                if ($config->hasToken()) {
                    $lines[] = I18n::t('cli.check.token');
                }
                if ($config->exempt() !== []) {
                    $lines[] = I18n::t('cli.check.exempt', ['paths' => implode(', ', $config->exempt())]);
                }
                $lines[] = I18n::t('cli.check.files', [
                    'media' => I18n::t($config->media() ? 'word.yes' : 'word.no'),
                    'assets' => I18n::t($config->assets() ? 'word.yes' : 'word.no'),
                ]);
                $lines[] = I18n::t('cli.check.style', ['source' => Page::styleSource($config)]);
        }

        // Sudare asks opcache to drop its copy of the file when it changed;
        // under opcache.restrict_api that call is refused and an old copy
        // holds until opcache revalidates it itself. This PHP's ini, which
        // is the server's on most hosts — a hint, not a failure.
        if (in_array($config->source(), [basename(Config::FILE), basename(Config::LEGACY_FILE)], true)
            && (string) ini_get('opcache.restrict_api') !== '') {
            $notes[] = I18n::t('cli.check.opcache', ['file' => Config::FILE]);
        }

        if ($code === self::EXIT_FAIL && $allowOff) {
            $lines[] = I18n::t('cli.check.allowOff');
            $code = self::EXIT_OK;
        }

        // Realm, paths and user come from the configuration: no control
        // character may reach the terminal or start a line of its own in the log.
        $failing = $code !== self::EXIT_OK;
        foreach ($notes as $note) {
            fwrite($out, I18n::printable($note) . "\n");
        }
        foreach ($lines as $line) {
            fwrite($out, ($github && $failing ? '::error::' : '') . I18n::printable($line) . "\n");
        }
        return $code;
    }

    /**
     * `--key=value` → ['key' => 'value'], `--flag` → ['flag' => true],
     * positionals → ['_' => [...]]; $argv[0] is skipped.
     *
     * @return array<string, mixed>
     */
    public static function arguments(array $argv): array
    {
        $result = [];
        $positionals = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (!is_string($argument)) {
                continue;
            }
            if (str_starts_with($argument, '--')) {
                $body = substr($argument, 2);
                if (str_contains($body, '=')) {
                    [$key, $value] = explode('=', $body, 2);
                    $result[$key] = $value;
                } else {
                    $result[$body] = true;
                }
                continue;
            }
            $positionals[] = $argument;
        }
        $result['_'] = $positionals;
        return $result;
    }

    /**
     * The --language value: 'en'|'de' → that, absent → null (caller decides),
     * anything else → false (usage error). Hidden flag values count as absent.
     *
     * @param array<string,mixed> $args
     */
    private static function languageOption(array $args): string|false|null
    {
        if (!array_key_exists('language', $args)) {
            return null;
        }
        $value = $args['language'];
        if (!is_string($value) || !in_array(strtolower(trim($value)), I18n::LANGUAGES, true)) {
            return false;
        }
        return strtolower(trim($value));
    }

    private static function setLanguage(string|false|null $language): void
    {
        I18n::setLanguage(is_string($language) ? $language : 'en');
    }

    private static function validUser(string $user): bool
    {
        $user = trim($user);
        return $user !== '' && !str_contains($user, ':') && preg_match('/[\x00-\x1F\x7F]/', $user) !== 1;
    }

    private static function absolute(string $path, string $cwd): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1) {
            return $path;
        }
        return rtrim($cwd, '/\\') . '/' . $path;
    }

    /**
     * Reads one line; the terminal does not echo it when $in is a TTY.
     *
     * @param resource $in
     * @param resource $err
     */
    private static function readPassword($in, $err): string
    {
        fwrite($err, I18n::t('cli.hash.prompt'));
        $tty = function_exists('stream_isatty') && @stream_isatty($in);
        if ($tty && !function_exists('shell_exec')) {
            // disable_functions on shared hosts: echo cannot be turned off.
            fwrite($err, "\n" . I18n::t('cli.hash.visible') . "\n" . I18n::t('cli.hash.prompt'));
            $tty = false;
        }
        if ($tty) {
            @shell_exec('stty -echo 2>/dev/null');
        }
        $line = fgets($in);
        if ($tty) {
            @shell_exec('stty echo 2>/dev/null');
        }
        // The newline the typing did not produce (echo off, or a pipe with no echo at all).
        fwrite($err, "\n");
        if ($line === false) {
            return '';
        }
        return rtrim($line, "\r\n");
    }

    /** The complete configuration file, as the SPEC lays it out (§9.1). */
    private static function configFile(string $user, string $hash, ?string $realm, ?string $tokenHash, bool $legacy): string
    {
        if ($legacy) {
            $inner = "'benutzer' => " . var_export($user, true) . ', ' . "'hash' => " . var_export($hash, true);
            if ($realm !== null) {
                $inner .= ', ' . "'realm' => " . var_export(trim($realm), true);
            }
            return "<?php\n\nreturn ['vorschau' => [" . $inner . "]];\n";
        }

        $body = "<?php\n\nreturn [\n";
        $body .= '    \'user\' => ' . var_export($user, true) . ",\n";
        $body .= '    \'hash\' => ' . var_export($hash, true) . ",\n";
        if ($realm !== null) {
            $body .= '    \'realm\' => ' . var_export(trim($realm), true) . ",\n";
        }
        if ($tokenHash !== null) {
            $body .= '    \'token\' => ' . var_export($tokenHash, true) . ",\n";
        }
        return $body . "];\n";
    }
}
