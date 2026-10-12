<?php

namespace Sudare;

/**
 * The bodies Sudare sends itself: the 401 page (HTML, themed, translated),
 * the 503 text and the sanitised WWW-Authenticate value (SPEC.md §3.6, §7). Pure.
 */
final class Page
{
    /** Full HTML document of the 401 page in the configuration's language and theme. */
    public static function denied(Config $config): string
    {
        I18n::setLanguage($config->language());
        $theme = $config->theme() + Config::DEFAULT_THEME;

        return '<!doctype html><html lang="' . self::esc($config->language()) . '"><head><meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="' . self::esc(Guard::ROBOTS) . '">' . "\n"
            . '<meta name="theme-color" content="' . self::esc($theme['chrome']) . '">' . "\n"
            . '<title>' . self::esc(I18n::t('denied.pageTitle')) . '</title>' . "\n"
            . '<style>' . self::style($config) . '</style>' . "\n"
            . '</head><body><h1>' . self::esc(I18n::t('denied.heading')) . '</h1>'
            . '<p>' . self::esc(I18n::t('denied.text')) . '</p></body></html>';
    }

    /** The content of the 401 page's <style> element: the theme's colours. */
    public static function style(Config $config): string
    {
        $theme = $config->theme() + Config::DEFAULT_THEME;
        return 'body{font:16px/1.6 -apple-system,system-ui,sans-serif;max-width:34rem;margin:18vh auto;'
            . 'padding:0 1.5rem;background:' . self::esc($theme['paper']) . ';color:' . self::esc($theme['ink']) . '}'
            . 'h1{font:600 26px/1.2 -apple-system,system-ui,sans-serif;color:' . self::esc($theme['deep']) . ';margin:0 0 .6rem}'
            . 'p{color:' . self::esc($theme['text']) . '}';
    }

    /**
     * The CSP source that allows exactly this <style> element, for sites whose
     * Content-Security-Policy says `style-src 'self'` (the starter's does):
     * without it the browser drops the colours and only `chrome` remains.
     */
    public static function styleSource(Config $config): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::style($config), true)) . "'";
    }

    /** Plain text of the 503 answer; never includes the reason or any value. */
    public static function unavailable(Config $config): string
    {
        I18n::setLanguage($config->language());
        return I18n::t('unavailable.text') . "\n";
    }

    /** `Basic realm="…", charset="UTF-8"` with quotes, backslashes and line breaks removed from the realm. */
    public static function realmHeader(string $realm): string
    {
        // Quotes, backslashes and every control character (CR, LF, NUL, tab…)
        // would break or split the header value.
        $clean = (string) preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $realm);
        if ($clean === '') {
            $clean = 'Preview';
        }
        return 'Basic realm="' . $clean . '", charset="UTF-8"';
    }

    /** HTML-escapes an interpolated value (SPEC.md §7.1). */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
