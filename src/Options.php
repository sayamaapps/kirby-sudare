<?php

namespace Sudare;

/**
 * Reads `sudare.*` settings from a raw configuration array and from the
 * environment. Shared file — owned by the architect (SPEC.md §4).
 *
 * Sudare runs before Kirby, so there is no Kirby option() to ask. The raw
 * array comes from site/config/sudare.php (or the legacy credentials.php),
 * the environment from SUDARE_* variables. Three spellings of a key work in
 * the array, like in the sibling plugins:
 *
 *   'sudare.user' => 'preview'            flat
 *   'sudare' => ['user' => 'preview']     nested
 *   'user' => 'preview'                   bare (the file is Sudare's own)
 *
 * `null` counts as "not set", so the default applies. Defaults are given at
 * the call sites (Config), never here.
 */
final class Options
{
    public const PREFIX = 'sudare.';

    public const ENV_PREFIX = 'SUDARE_';

    /** Every key Sudare reads (SPEC.md §4). Unknown keys are ignored. */
    public const KEYS = ['active', 'user', 'hash', 'realm', 'token', 'exempt', 'language', 'theme', 'media', 'assets'];

    /** Keys whose value is a secret and must never be printed or returned by sudare_option(). */
    public const SECRET_KEYS = ['hash', 'token'];

    /** Keys that may come from the environment (theme cannot). */
    public const ENV_KEYS = ['active', 'user', 'hash', 'realm', 'token', 'exempt', 'language', 'media', 'assets'];

    public static function get(array $raw, string $key, mixed $default = null): mixed
    {
        if (($raw[self::PREFIX . $key] ?? null) !== null) {
            return $raw[self::PREFIX . $key];
        }
        $nested = $raw['sudare'] ?? null;
        if (is_array($nested) && ($nested[$key] ?? null) !== null) {
            return $nested[$key];
        }
        return $raw[$key] ?? $default;
    }

    /** Is the key present in any spelling (even with a broken value)? */
    public static function has(array $raw, string $key): bool
    {
        $nested = $raw['sudare'] ?? null;
        return array_key_exists(self::PREFIX . $key, $raw)
            || (is_array($nested) && array_key_exists($key, $nested))
            || array_key_exists($key, $raw);
    }

    /** `user` → `SUDARE_USER`. */
    public static function envKey(string $key): string
    {
        return self::ENV_PREFIX . strtoupper($key);
    }

    /** The environment value as a string; an empty string counts as unset. */
    public static function env(array $env, string $key, mixed $default = null): mixed
    {
        $value = $env[self::envKey($key)] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Does the environment alone configure Sudare: is a SUDARE_* variable of
     * ENV_KEYS set (non-empty)? SUDARE_LANGUAGE does not count: it also picks
     * the language of the CLIs, and a shell or server that sets it for that
     * must not turn a site without configuration into a broken one (503).
     */
    public static function envPresent(array $env): bool
    {
        foreach (self::ENV_KEYS as $key) {
            if ($key !== 'language' && self::env($env, $key) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The raw array with every ENV_KEYS value from the environment laid over
     * it (bare spelling). Environment wins per key; file values of other keys
     * stay. Used by Config before validation.
     */
    public static function mergeEnv(array $raw, array $env): array
    {
        foreach (self::ENV_KEYS as $key) {
            $value = self::env($env, $key);
            if ($value !== null) {
                unset($raw[self::PREFIX . $key]);
                if (isset($raw['sudare']) && is_array($raw['sudare'])) {
                    unset($raw['sudare'][$key]);
                }
                $raw[$key] = $value;
            }
        }
        return $raw;
    }

    /**
     * A boolean from a file (real bool) or the environment (string):
     * true/'true'/'1'/'yes'/'on' → true, false/'false'/'0'/'no'/'off' → false,
     * anything else → null (the caller treats it as broken).
     */
    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        return match (strtolower(trim($value))) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * A list of strings from a file (array) or the environment ("a, b"):
     * trimmed, empty entries dropped. null when the value is neither.
     *
     * @return list<string>|null
     */
    public static function list(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return null;
        }
        $list = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return null;
            }
            $entry = trim($entry);
            if ($entry !== '') {
                $list[] = $entry;
            }
        }
        return $list;
    }
}
