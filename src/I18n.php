<?php

namespace Sudare;

/**
 * Strings of the package, Kirby-free. Shared file — owned by the architect
 * (SPEC.md §5).
 *
 * The tables live in translations/<language>.php. Sudare runs before Kirby,
 * so unlike its sibling plugins it cannot register them as Kirby translations
 * itself; a site that wants `t('sudare.<key>')` registers the tables in a
 * plugin of its own (README, "Translations").
 *
 * The language is set once per request from the configuration
 * (Config::language(), default English). Missing keys fall back to English,
 * then to the key itself.
 */
final class I18n
{
    /** Languages Sudare ships. English is the default and the fallback. */
    public const LANGUAGES = ['en', 'de'];

    private static string $language = 'en';

    /** @var array<string, array<string,string>> */
    private static array $tables = [];

    public static function setLanguage(string $language): void
    {
        self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
    }

    public static function language(): string
    {
        return self::$language;
    }

    /** @return array<string,string> */
    public static function table(string $language): array
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            return [];
        }
        return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
    }

    /**
     * The text without control characters (C0 including line breaks, DEL,
     * C1), for one line of terminal or CI-log output. Invalid UTF-8 loses
     * the bytes of those ranges instead.
     */
    public static function printable(string $text): string
    {
        return preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', '', $text)
            ?? (string) preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $text);
    }

    /** `{name}` placeholders are replaced with the given values, unescaped. */
    public static function t(string $key, array $values = []): string
    {
        $text = self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
        if ($values === []) {
            return $text;
        }
        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $pairs);
    }
}
