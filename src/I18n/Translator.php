<?php

declare(strict_types=1);

namespace HolyMD\I18n;

/**
 * Administrator interface translations. English source strings are the
 * catalog keys, so an untranslated string falls back to readable English.
 */
final class Translator
{
    public const LOCALES = ['en', 'zh-CN'];
    public const COOKIE = 'holymd_admin_locale';

    private static string $locale = 'en';

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Pick the first valid locale: explicit request, saved cookie, configured
     * default, then the public site language.
     */
    public static function resolve(?string $requested, ?string $cookie, ?string $configured, string $siteLanguage): string
    {
        foreach ([$requested, $cookie, $configured] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::LOCALES, true)) {
                return $candidate;
            }
        }
        return str_starts_with(strtolower($siteLanguage), 'zh') ? 'zh-CN' : 'en';
    }

    /**
     * Translate plain text. Placeholders such as {count} are replaced after
     * translation. The result is not HTML-escaped.
     *
     * @param array<string, string|int> $params
     */
    public static function text(string $source, array $params = []): string
    {
        $translated = self::catalog(self::$locale)[$source] ?? $source;
        if ($params === []) {
            return $translated;
        }
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }
        return strtr($translated, $replacements);
    }

    /** @return array<string, string> */
    public static function catalog(string $locale): array
    {
        if ($locale === 'en') {
            return [];
        }
        if (!isset(self::$catalogs[$locale])) {
            $catalog = require __DIR__ . '/catalog/' . $locale . '.php';
            self::$catalogs[$locale] = is_array($catalog) ? $catalog : [];
        }
        return self::$catalogs[$locale];
    }

    /**
     * Strings used by admin.js, passed to the browser as a lookup table.
     *
     * @return array<string, string>
     */
    public static function scriptCatalog(): array
    {
        $catalog = self::catalog(self::$locale);
        return array_intersect_key($catalog, array_flip(self::SCRIPT_STRINGS));
    }

    public const SCRIPT_STRINGS = [
        'Preview failed',
        'Saving…',
        'Save failed',
        'Source saved',
        'Unsaved changes',
        'Save failed; publication was cancelled.',
        '{count} configured',
        'Copied',
        'Copy failed',
        'GEO status failed',
        'GEO review failed',
        'Metadata suggestions applied',
        'Metadata suggestions ready',
        'Suggest metadata',
        'Retry GEO review',
        'Refresh GEO status',
        'GEO review running…',
        'GEO review queued — waiting for Cron worker…',
        'Starting analysis…',
    ];
}
