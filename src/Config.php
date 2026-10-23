<?php

namespace Sudare;

/**
 * The validated configuration of one request: where it came from, whether
 * the blind is down, and every setting Guard, Media, Page and the CLIs need.
 * Kirby-free, immutable. Written by the architect (SPEC.md §3.4), verified
 * in tests/ConfigTest.php.
 *
 * Sources, in this order (SPEC.md §4.1):
 *   1. <base>/site/config/sudare.php      — English keys (bare, nested or flat)
 *   2. <base>/site/config/credentials.php — the legacy file of the sites:
 *      ['vorschau' => ['benutzer' => …, 'hash' => …, 'realm' => …]]
 *   3. SUDARE_* environment variables      — laid over the file, per key
 *
 * States (SPEC.md §4.2):
 *   none   — no file, no SUDARE_* variable: Sudare does nothing at all
 *   off    — a source exists and says active => false: nothing happens either
 *   broken — a source exists but cannot be trusted: EVERY request gets 503
 *            (fail closed), reason() says why — never with secret values
 *   on     — valid: Basic Auth in front of Kirby
 *
 * Secrets (hash, token) never leave this object except through hash() and
 * token(), which Guard alone calls; toArray() and option() withhold them.
 */
final class Config
{
    public const STATE_NONE = 'none';
    public const STATE_OFF = 'off';
    public const STATE_BROKEN = 'broken';
    public const STATE_ON = 'on';

    public const FILE = 'site/config/sudare.php';
    public const LEGACY_FILE = 'site/config/credentials.php';

    /** Always exempt, even while the configuration is broken (SPEC.md §6.4). */
    public const ACME = '/.well-known/acme-challenge';

    /** A hash containing one of these is the template's example and never valid. */
    public const EXAMPLE_MARKERS = ['ERSETZEN', 'REPLACE'];

    /** Colour roles of the 401 page; every key must be a CSS hex colour. */
    public const THEME_KEYS = ['chrome', 'paper', 'ink', 'deep', 'text'];

    /** The default colours (SPEC.md §4.3). */
    public const DEFAULT_THEME = [
        'chrome' => '#1E3648',
        'paper'  => '#FFFFFF',
        'ink'    => '#15222B',
        'deep'   => '#1E3648',
        'text'   => '#41505A',
    ];

    /** Results of readFile() by real path and content hash. */
    private static array $read = [];

    /** True while readFile() runs a configuration file. */
    private static bool $including = false;

    private static bool $shutdownGuarded = false;

    /**
     * @param list<string> $exempt entries in normal form (exemptEntry())
     * @param array<string,string> $theme
     * @param array<string,mixed> $raw merged, non-secret options for option()
     */
    private function __construct(
        private string $state,
        private ?string $source,
        private ?string $reason,
        private ?string $user,
        private ?string $hash,
        private ?string $token,
        private string $realm,
        private array $exempt,
        private string $language,
        private array $theme,
        private bool $media,
        private bool $assets,
        private array $raw,
    ) {
    }

    /* ---------------------------------------------------------------- loading */

    /**
     * Loads the configuration of a project: sudare.php, else credentials.php,
     * each with the environment laid over it; the environment alone when no
     * file exists; state `none` when there is nothing.
     *
     * @param array<string,mixed> $env usually getenv() + $_SERVER's SUDARE_* keys
     */
    public static function load(string $base, array $env = []): self
    {
        return self::quietly(static fn (): self => self::read($base, $env));
    }

    /**
     * Runs the loading with Sudare's own error handler in place. A site's
     * handler set before guard() may throw on every warning (Kirby's own
     * does under a debug flag; many apps do): the open_basedir warning of
     * is_file() or the restrict_api warning of opcache_invalidate() would
     * then end the request with a 500 before any protective header is sent.
     * The site stays closed either way; this keeps it a 401/503. A warning
     * from inside the configuration file is swallowed too: nothing of the
     * file may reach the page.
     */
    private static function quietly(callable $load): self
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $load();
        } finally {
            restore_error_handler();
        }
    }

    private static function read(string $base, array $env): self
    {
        $base = rtrim($base, '/\\');
        // The directories must be usable too: behind a dangling link or a
        // directory PHP may not search, both files would look absent and
        // the site would open.
        foreach ([$base . '/site', $base . '/site/config'] as $dir) {
            if (self::present($dir) && !self::searchable($dir)) {
                return self::broken('unreadable', substr($dir, strlen($base) + 1), Options::mergeEnv([], $env));
            }
        }
        foreach ([self::FILE, self::LEGACY_FILE] as $relative) {
            $file = $base . '/' . $relative;
            // A dangling symlink is "present" too: deploys ship the file as a
            // link sometimes, and a broken link must never open the site.
            if (self::present($file)) {
                return self::fromFile($file, $env);
            }
        }
        if (Options::envPresent($env)) {
            return self::fromArray([], $env, 'environment');
        }
        return self::none();
    }

    /**
     * Is there an entry at this path, even one PHP may not follow? Under
     * open_basedir, file_exists() and is_link() are false for a link whose
     * target lies outside (a shared config of Deployer/Capistrano), which
     * would open the site. PHP says so itself: file_exists() warns about
     * open_basedir only when the path resolves outside (never for a missing
     * file), and the parent directory still lists the name — unless it has
     * no read permission, so the warning comes first.
     */
    private static function present(string $path): bool
    {
        // An own handler for the moment: a site's handler set before guard()
        // could swallow the warning (error_get_last() stays empty) or throw;
        // and no warning may reach the page.
        $basedir = false;
        set_error_handler(static function (int $no, string $message) use (&$basedir): bool {
            $basedir = $basedir || str_contains($message, 'open_basedir');
            return true;
        });
        try {
            return file_exists($path) || is_link($path) || $basedir
                || in_array(basename($path), scandir(dirname($path)) ?: [], true);
        } finally {
            restore_error_handler();
        }
    }

    /** A directory PHP can look into (the search bit; Windows has none to check). */
    private static function searchable(string $dir): bool
    {
        return @is_dir($dir) && (PHP_OS_FAMILY === 'Windows' || @is_executable($dir));
    }

    /** Reads and validates one file; the environment is laid over it. */
    public static function fromFile(string $file, array $env = []): self
    {
        return self::quietly(static fn (): self => self::readOne($file, $env));
    }

    private static function readOne(string $file, array $env): self
    {
        $source = basename($file);
        $raw = self::readFile($file);
        if (is_string($raw)) {
            // A legacy file speaks German even when it cannot be read.
            $merged = Options::mergeEnv([], $env);
            $language = $source === basename(self::LEGACY_FILE) ? self::readLanguage($merged, 'de') : null;
            return self::broken($raw, $source, $merged, $language);
        }
        return self::fromArray($raw, $env, $source);
    }

    /**
     * Includes a configuration file without letting anything out: output is
     * swallowed, every Throwable (ParseError included) becomes a reason code.
     * A file that ends the script instead (exit, die, a fatal error) is
     * caught at shutdown: its output is discarded and the answer is a 503
     * (exit code 2 in a CLI).
     *
     * Each file runs once per request: guard() and a later protects() get
     * the same result, and a function the file declares is not declared twice.
     *
     * @return array<mixed>|string the raw array, or a reason code
     *         ('unreadable', 'syntax', 'notArray')
     */
    public static function readFile(string $file): array|string
    {
        if (!@is_file($file) || !@is_readable($file)) {
            return 'unreadable';
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return 'unreadable';
        }
        $key = (realpath($file) ?: $file) . "\0" . hash('sha256', $content);
        if (array_key_exists($key, self::$read)) {
            return self::$read[$key];
        }

        self::guardShutdown();
        self::$including = true;
        $failed = false;
        // Opcache may still hold the bytecode of an older file (a rotated
        // password, a removed token): with the default validate_timestamps=1
        // it checks only every revalidate_freq seconds; with 0 never. Without
        // `force`, opcache compares the file's mtime and drops the copy only
        // when it changed — forcing would waste ~1 KB of shared memory per
        // request until opcache restarts (every few thousand requests, for
        // every site of the pool). With validate_timestamps=0 there is no
        // mtime to compare and every request drops the copy; the README
        // names opcache.blacklist_filename for that case. Under
        // opcache.restrict_api the call is refused (false) and the old file
        // holds: sudare-check says so.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, false);
        }
        // A fatal error the file cannot recover from (memory exhausted) is
        // printed by PHP itself, past the output buffer, with the file's path
        // and a stack trace — when display_errors is on, as in PHP's default
        // and php.ini-development. Off while the file runs; the shutdown
        // function answers 503 without it.
        $display = ini_set('display_errors', '0');
        ob_start();
        try {
            $raw = require $file;
        } catch (\Throwable) {
            $failed = true;
        } finally {
            ob_end_clean();
            self::$including = false;
            if ($display !== false) {
                ini_set('display_errors', $display);
            }
        }
        return self::$read[$key] = $failed ? 'syntax' : (is_array($raw) ? $raw : 'notArray');
    }

    /**
     * When the configuration file ends the script, `finally` never runs and
     * PHP would send what the file printed with status 200. The shutdown
     * function discards it and fails closed instead.
     */
    private static function guardShutdown(): void
    {
        if (self::$shutdownGuarded) {
            return;
        }
        self::$shutdownGuarded = true;
        register_shutdown_function(static function (): void {
            if (!self::$including) {
                return;
            }
            while (ob_get_level() > 0 && @ob_end_clean()) {
            }
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, I18n::t('cli.check.broken', ['reason' => I18n::t('reason.exit')]) . "\n");
                exit(Cli::EXIT_BROKEN);
            }
            if (!headers_sent()) {
                // After a fatal error PHP has already set "HTTP/1.0 500" as a
                // status line (display_errors off), and http_response_code()
                // cannot replace that; a status line of our own can.
                header('HTTP/1.1 503 Service Unavailable', true, 503);
                header('Content-Type: text/plain; charset=utf-8');
                foreach (Guard::headers() + ['Retry-After' => Guard::RETRY_AFTER] as $name => $value) {
                    header($name . ': ' . $value, true);
                }
            }
            echo I18n::t('unavailable.text') . "\n";
        });
    }

    /**
     * Validates a raw array (any spelling, legacy included) with the
     * environment laid over it. $source is reported by toArray() and the
     * check CLI only.
     *
     * @param array<mixed> $raw
     * @param array<string,mixed> $env
     */
    public static function fromArray(array $raw, array $env = [], string $source = 'array'): self
    {
        $legacy = self::isLegacy($raw);
        if ($legacy) {
            $raw = self::fromLegacy($raw);
        }
        $raw = Options::mergeEnv($raw, $env);

        $language = self::readLanguage($raw, $legacy ? 'de' : 'en');

        $active = self::setting($raw, 'active');
        $active = $active === null ? true : Options::bool($active);
        if ($active === null) {
            return self::broken('active', $source, $raw, $language);
        }

        // media and assets count while switched off too: they decide whether
        // Sudare serves files on the open site.
        $media = self::readFlag($raw, 'media');
        $assets = self::readFlag($raw, 'assets');
        if ($active === false) {
            if ($media === null || $assets === null) {
                return self::broken($media === null ? 'media' : 'assets', $source, $raw, $language);
            }
            return new self(self::STATE_OFF, $source, null, null, null, null, I18n::table($language)['realm'] ?? 'Preview', [], $language, self::DEFAULT_THEME, $media, $assets, self::publicRaw($raw));
        }

        $user = Options::get($raw, 'user');
        if (!is_string($user) || trim($user) === '' || str_contains($user, ':') || preg_match('/[\x00-\x1F\x7F]/', $user) === 1) {
            return self::broken('user', $source, $raw, $language);
        }
        $user = trim($user);

        // Hashes never contain whitespace: a secret pasted with a trailing
        // newline (echo, a CI secret from a file) is the same hash.
        $hash = Options::get($raw, 'hash');
        if (!is_string($hash)) {
            return self::broken('hash', $source, $raw, $language);
        }
        $hash = trim($hash);
        // The template's example first: it is not a valid hash either, but
        // the message should say what actually happened.
        foreach (self::EXAMPLE_MARKERS as $marker) {
            if (str_contains($hash, $marker)) {
                return self::broken('example', $source, $raw, $language);
            }
        }
        if (!self::isHash($hash)) {
            return self::broken('hash', $source, $raw, $language);
        }

        $token = self::setting($raw, 'token');
        $token = is_string($token) ? trim($token) : $token;
        if ($token !== null && (!is_string($token) || !self::isHash($token))) {
            return self::broken('token', $source, $raw, $language);
        }

        $realm = Options::get($raw, 'realm');
        if ($realm !== null && !is_string($realm)) {
            return self::broken('realm', $source, $raw, $language);
        }
        $realm = is_string($realm) && trim($realm) !== '' ? trim($realm) : (I18n::table($language)['realm'] ?? 'Preview');

        $exempt = [];
        if (Options::has($raw, 'exempt') && Options::get($raw, 'exempt') !== null) {
            $list = Options::list(Options::get($raw, 'exempt'));
            if ($list === null) {
                return self::broken('exempt', $source, $raw, $language);
            }
            foreach ($list as $entry) {
                $parsed = self::exemptEntry($entry);
                if ($parsed === null) {
                    return self::broken('exempt', $source, $raw, $language);
                }
                $exempt[] = $parsed;
            }
            $exempt = array_values(array_unique($exempt));
        }

        // Only strings can name a language: an array or a number must not be
        // cast (PHP warns on array-to-string, and the warning would reach the page).
        if (self::setting($raw, 'language') !== null) {
            $value = self::setting($raw, 'language');
            if (!is_string($value) || !in_array(strtolower(trim($value)), I18n::LANGUAGES, true)) {
                return self::broken('language', $source, $raw, $language);
            }
        }

        if ($media === null) {
            return self::broken('media', $source, $raw, $language);
        }
        if ($assets === null) {
            return self::broken('assets', $source, $raw, $language);
        }

        $theme = self::readTheme($raw);
        if ($theme === null) {
            return self::broken('theme', $source, $raw, $language);
        }

        return new self(self::STATE_ON, $source, null, $user, $hash, $token, $realm, $exempt, $language, $theme, $media, $assets, self::publicRaw($raw));
    }

    public static function none(): self
    {
        return new self(self::STATE_NONE, null, null, null, null, null, I18n::table('en')['realm'] ?? 'Preview', [], 'en', self::DEFAULT_THEME, true, true, []);
    }

    /* ----------------------------------------------------------- the legacy file */

    /**
     * The sites' credentials.php: a `vorschau` array and none of the English
     * keys. (A credentials.php written in the new format is read as new.)
     */
    public static function isLegacy(array $raw): bool
    {
        return isset($raw['vorschau']) && is_array($raw['vorschau'])
            && !isset($raw['user']) && !isset($raw['sudare']) && !isset($raw['sudare.user']);
    }

    /**
     * `vorschau.benutzer` → user, `vorschau.hash` → hash, `vorschau.realm` →
     * realm; other `vorschau` keys are dropped. English keys next to
     * `vorschau` (any spelling: `'active' => false` to park the file) count
     * too and win.
     */
    public static function fromLegacy(array $raw): array
    {
        $legacy = $raw['vorschau'];
        $mapped = [];
        if (array_key_exists('benutzer', $legacy)) {
            $mapped['user'] = $legacy['benutzer'];
        }
        if (array_key_exists('hash', $legacy)) {
            $mapped['hash'] = $legacy['hash'];
        }
        if (array_key_exists('realm', $legacy)) {
            $mapped['realm'] = $legacy['realm'];
        }
        foreach (Options::KEYS as $key) {
            if (Options::get($raw, $key) !== null) {
                $mapped[$key] = Options::get($raw, $key);
            }
        }
        return $mapped;
    }

    /* -------------------------------------------------------------- validation */

    /**
     * Whether a string has the shape of a password hash, judged by syntax only.
     *
     * password_get_info() checks just the prefix and, for bcrypt, the length:
     * any 60-character string starting with `$2y$` is "bcrypt" to it, cost
     * `99` or a `!` in the salt included. A hash damaged while the
     * configuration was edited or copied must be `broken` (503, "configuration
     * broken"), not `on` with every login answering 401. So:
     *
     * - `$2…`: the full bcrypt shape for every prefix password_verify()
     *   accepts (`$2a$`, `$2b$`, `$2x$`, `$2y$`): cost 04–31, then 22 salt and
     *   31 hash characters of the bcrypt alphabet, 60 characters in all. Never
     *   a bare prefix test, whatever password_get_info() says.
     * - `$argon2…`: the encoded form (`m=,t=,p=`, salt, hash in base64) and
     *   a PHP that knows Argon2, so a build without it still fails closed.
     * - anything else: what password_get_info() recognises.
     *
     * Regular expressions only, never password_verify() or crypt(): those
     * would take years for a well-formed cost-31 hash. `D` on the patterns,
     * so a trailing newline is not part of a hash; isHash() does not trim,
     * fromArray() does before it asks. A hash with the right shape that is
     * the hash of nothing simply fails at login.
     */
    public static function isHash(string $hash): bool
    {
        if (str_starts_with($hash, '$2')) {
            return self::isBcrypt($hash);
        }
        $known = (password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown';
        return str_starts_with($hash, '$argon2') ? self::isArgon2($hash) && $known : $known;
    }

    /** The encoded Argon2 form; `v=` is optional (older encodings), the lengths are not checked. */
    private static function isArgon2(string $hash): bool
    {
        return preg_match('#^\$argon2id?\$(?:v=\d+\$)?m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+$#D', $hash) === 1;
    }

    /** The full bcrypt shape, any prefix password_verify() takes (python-bcrypt writes `$2b$`, some htpasswd builds `$2a$`/`$2x$`). */
    private static function isBcrypt(string $hash): bool
    {
        return preg_match('#^\$2[abxy]\$(?:0[4-9]|[12][0-9]|3[01])\$[./A-Za-z0-9]{53}$#D', $hash) === 1;
    }

    private static function readLanguage(array $raw, string $default): string
    {
        $value = Options::get($raw, 'language');
        if (is_string($value) && in_array(strtolower(trim($value)), I18n::LANGUAGES, true)) {
            return strtolower(trim($value));
        }
        return $default;
    }

    /**
     * An optional setting, or null when it is not set. `null` and an empty
     * string both mean "not set", as for the environment (Options::env()):
     * `'token' => ''` is no token, not a broken one.
     */
    private static function setting(array $raw, string $key): mixed
    {
        $value = Options::get($raw, $key);
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /** true when the key is not set; null when its value is no boolean. */
    private static function readFlag(array $raw, string $key): ?bool
    {
        $value = self::setting($raw, $key);
        return $value === null ? true : Options::bool($value);
    }

    /**
     * `theme`: an array of THEME_KEYS → "#rgb", "#rrggbb" or "#rrggbbaa";
     * missing keys keep the default. Anything else is broken (null).
     *
     * @return array<string,string>|null
     */
    private static function readTheme(array $raw): ?array
    {
        $theme = self::DEFAULT_THEME;
        $value = self::setting($raw, 'theme');
        if ($value === null) {
            return $theme;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $key => $colour) {
            if (!in_array($key, self::THEME_KEYS, true) || !is_string($colour)
                || preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', trim($colour)) !== 1) {
                return null;
            }
            $theme[$key] = strtoupper(trim($colour));
        }
        return $theme;
    }

    private static function broken(string $reason, ?string $source, array $raw, ?string $language = null): self
    {
        $language ??= self::readLanguage($raw, 'en');
        return new self(self::STATE_BROKEN, $source, $reason, null, null, null, I18n::table($language)['realm'] ?? 'Preview', [], $language, self::DEFAULT_THEME, true, true, self::publicRaw($raw));
    }

    /** Every spelling flattened to bare keys, secrets removed, unknown keys dropped. */
    private static function publicRaw(array $raw): array
    {
        $public = [];
        foreach (Options::KEYS as $key) {
            if (in_array($key, Options::SECRET_KEYS, true)) {
                continue;
            }
            if (Options::has($raw, $key)) {
                $public[$key] = Options::get($raw, $key);
            }
        }
        return $public;
    }

    /* ----------------------------------------------------------------- reading */

    /** none | off | broken | on */
    public function state(): string
    {
        return $this->state;
    }

    /** The blind is down: valid configuration, Basic Auth required. */
    public function protects(): bool
    {
        return $this->state === self::STATE_ON;
    }

    /** Something is configured (on or broken): headers go out, nothing opens by accident. */
    public function enabled(): bool
    {
        return $this->state === self::STATE_ON || $this->state === self::STATE_BROKEN;
    }

    /** 'sudare.php', 'credentials.php', 'environment', 'array', the unusable directory ('site', 'site/config') or null (none). */
    public function source(): ?string
    {
        return $this->source;
    }

    /** Reason code while broken (translation key `reason.<code>`), else null. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function user(): ?string
    {
        return $this->user;
    }

    /** The password hash — Guard only. */
    public function hash(): ?string
    {
        return $this->hash;
    }

    /** The machine token's hash, or null when none is configured — Guard only. */
    public function token(): ?string
    {
        return $this->token;
    }

    public function hasToken(): bool
    {
        return $this->token !== null;
    }

    /** Realm for the browser dialog, unsanitised (Page::realmHeader() cleans it). */
    public function realm(): string
    {
        return $this->realm;
    }

    /** @return list<string> exempt entries in normal form ("POST /api/hook", "/feed/*"), without the built-in ACME path */
    public function exempt(): array
    {
        return $this->exempt;
    }

    /**
     * One `exempt` entry in normal form, or null when it is invalid:
     * `[METHOD ]/path[/*]`. Without `/*` only the path itself matches (a
     * sub-path would reach Kirby's error page, with the site's navigation);
     * with a method only that method (GET includes HEAD). "/" alone, a "*"
     * elsewhere and a path without "/" are invalid.
     */
    public static function exemptEntry(string $entry): ?string
    {
        // A method Kirby does not route could never be what Kirby sees. No
        // OPTIONS entry: Sudare answers preflights itself (row 5), and an
        // exempt OPTIONS would reach Kirby's page route, which renders the
        // page with its body (Kirby's CORS route steps aside without
        // Access-Control-Request-Method).
        if (preg_match('~^(?:([A-Za-z]+)\s+)?(/\S*)$~', trim($entry), $m) !== 1
            || ($m[1] !== '' && !in_array(strtoupper($m[1]), Request::KIRBY_METHODS, true))
            || strtoupper($m[1]) === 'OPTIONS') {
            return null;
        }
        $path = $m[2];
        // A double slash is refused as written (README), not normalised away.
        if (str_contains($path, '//')) {
            return null;
        }
        $below = str_ends_with($path, '/*');
        if ($below) {
            $path = substr($path, 0, -2);
        }
        // Already in normal form, as a request has to be to match: no "%"
        // (decoding "/a/%2A" would make it "/a/*"), no "." or ".." segment
        // ("/x/../panel" would be the Panel), no empty segment, at most one
        // trailing slash. Only letters, digits, "-", "_", "." and "/": Kirby
        // takes a segment with ":" (";" on Windows) as a URL parameter and
        // routes without it, so "/lang:de/*" would let "/lang:de/panel" reach
        // the Panel. Anything else is a typo or a trick: broken.
        $plain = $path !== '/' && str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $path) !== 1 || Request::normalisePath($plain) !== $plain) {
            return null;
        }
        $path = $plain;
        if ($path === '/') {
            return null;
        }
        return ($m[1] !== '' ? strtoupper($m[1]) . ' ' : '') . $path . ($below ? '/*' : '');
    }

    /**
     * Is this normalised path exempt from authentication for this method?
     * The ACME challenge path always is (and everything below it). An entry
     * "/api/hook" matches "/api/hook" only, "/api/hook/*" also "/api/hook/1"
     * (never "/api/hooks"), "POST /api/hook" only a POST. $method is the
     * method Kirby routes; $sent REQUEST_METHOD as sent: a "HEAD" entry needs
     * exactly "HEAD" on the wire, or a GET overridden to HEAD, or a "head",
     * would get the page with its body (Apache and PHP drop it only for an
     * exact "HEAD").
     */
    public function isExempt(string $path, string $method = 'GET', ?string $sent = null): bool
    {
        // Without the method as sent, the routed one stands for it (never looser).
        $sent ??= $method;
        if (self::startsWithSegment($path, self::ACME)) {
            return true;
        }
        foreach ($this->exempt as $entry) {
            [$only, $target] = str_contains($entry, ' ') ? explode(' ', $entry, 2) : [null, $entry];
            // An entry with a method needs it twice: as Kirby routes it and as
            // sent (no X-HTTP-Method-Override trick in either direction), and
            // a HEAD entry an exact "HEAD", the only one that loses its body.
            $fits = static fn (string $m): bool => $m === $only || ($only === 'GET' && $m === 'HEAD');
            if ($only !== null && (!$fits($method) || ($sent !== null && !$fits($sent)))) {
                continue;
            }
            if ($only === 'HEAD' && $sent !== null && $sent !== 'HEAD') {
                continue;
            }
            $matches = str_ends_with($target, '/*')
                ? self::startsWithSegment($path, substr($target, 0, -2))
                : $path === $target;
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    public static function startsWithSegment(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /** en | de */
    public function language(): string
    {
        return $this->language;
    }

    /** @return array<string,string> every THEME_KEYS key, upper-case hex */
    public function theme(): array
    {
        return $this->theme;
    }

    /** Serve files under public/media through PHP (default true). */
    public function media(): bool
    {
        return $this->media;
    }

    /** Serve files under public/assets through PHP when they reach it (default true). */
    public function assets(): bool
    {
        return $this->assets;
    }

    /** Name of the algorithm of the password hash ("bcrypt", "argon2id"), or null. */
    public function algorithm(): ?string
    {
        if ($this->hash === null) {
            return null;
        }
        if (!self::isHash($this->hash)) {
            return null;
        }
        return str_starts_with($this->hash, '$2') ? 'bcrypt' : password_get_info($this->hash)['algoName'];
    }

    /**
     * A non-secret option as configured (bare, nested or flat spelling, env
     * laid over), or the default. hash and token always return null.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        if (in_array($key, Options::SECRET_KEYS, true)) {
            return null;
        }
        return $this->raw[$key] ?? $default;
    }

    /**
     * For the check CLI and diagnostics. No secret values.
     *
     * @return array{state: string, source: ?string, reason: ?string, user: ?string, algorithm: ?string, realm: string, token: bool, exempt: list<string>, language: string, media: bool, assets: bool}
     */
    public function toArray(): array
    {
        return [
            'state'     => $this->state,
            'source'    => $this->source,
            'reason'    => $this->reason,
            'user'      => $this->user,
            'algorithm' => $this->algorithm(),
            'realm'     => $this->realm,
            'token'     => $this->token !== null,
            'exempt'    => $this->exempt,
            'language'  => $this->language,
            'media'     => $this->media,
            'assets'    => $this->assets,
        ];
    }
}
