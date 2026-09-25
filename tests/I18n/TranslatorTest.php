<?php

declare(strict_types=1);

namespace HolyMD\Tests\I18n;

use HolyMD\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    /** Keys built at runtime rather than written as literals. */
    private const DYNAMIC_KEYS = [
        'Draft', 'Published', 'Withdrawn',
        'geo_review', 'build', 'queued', 'running', 'succeeded', 'failed', 'publish', 'withdraw',
        'New Publication', 'Title', 'Date', 'Body', 'Summary', 'Topics', 'Entities', 'Faq', 'Sources',
        'Alt Text', 'Hierarchy', 'Internal Links', 'Previous Slugs', 'Structured Data',
        'Failed', 'Article cited', 'Site cited', 'Mentioned', 'Not cited',
    ];

    protected function tearDown(): void
    {
        Translator::setLocale('en');
    }

    public function test_resolve_prefers_request_then_cookie_then_configuration_then_site_language(): void
    {
        self::assertSame('en', Translator::resolve('en', 'zh-CN', 'zh-CN', 'zh-CN'));
        self::assertSame('zh-CN', Translator::resolve('fr', 'zh-CN', 'en', 'en'));
        self::assertSame('en', Translator::resolve(null, 'bogus', 'en', 'zh-CN'));
        self::assertSame('zh-CN', Translator::resolve(null, null, null, 'zh-TW'));
        self::assertSame('en', Translator::resolve(null, null, '', 'ja'));
    }

    public function test_unknown_locales_fall_back_to_english(): void
    {
        Translator::setLocale('fr');

        self::assertSame('en', Translator::locale());
        self::assertSame('Articles', Translator::text('Articles'));
    }

    public function test_translates_and_fills_placeholders_after_translation(): void
    {
        self::assertSame('Restore abc', Translator::text('Restore {version}', ['version' => 'abc']));

        Translator::setLocale('zh-CN');

        self::assertSame('恢复 abc', Translator::text('Restore {version}', ['version' => 'abc']));
        self::assertSame('Untranslated source', Translator::text('Untranslated source'));
    }

    public function test_html_helper_escapes_translated_text_and_parameters(): void
    {
        self::assertSame('Edit &lt;b&gt;', __('Edit {title}', ['title' => '<b>']));
    }

    public function test_every_interface_string_has_a_chinese_translation_with_matching_placeholders(): void
    {
        $catalog = Translator::catalog('zh-CN');
        $keys = [...$this->sourceKeys(), ...self::DYNAMIC_KEYS, ...Translator::SCRIPT_STRINGS];

        $missing = array_values(array_diff(array_unique($keys), array_keys($catalog)));
        self::assertSame([], $missing, 'Add these strings to src/I18n/catalog/zh-CN.php');

        foreach ($catalog as $source => $translation) {
            preg_match_all('/\{[a-z]+\}/', $source, $expected);
            preg_match_all('/\{[a-z]+\}/', $translation, $actual);
            $expectedNames = $expected[0];
            $actualNames = $actual[0];
            sort($expectedNames);
            sort($actualNames);
            self::assertSame($expectedNames, $actualNames, 'Placeholder mismatch for: ' . $source);
        }
    }

    public function test_every_admin_script_string_is_sent_to_the_browser(): void
    {
        preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.js'), $matches);

        self::assertNotEmpty($matches[1]);
        self::assertSame([], array_values(array_diff(array_unique($matches[1]), Translator::SCRIPT_STRINGS)));
    }

    /** @return list<string> */
    private function sourceKeys(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [
            ...(glob($root . '/templates/admin/*.php') ?: []),
            ...(glob($root . '/templates/admin/*/*.php') ?: []),
            ...(glob($root . '/templates/public/*.php') ?: []),
            $root . '/src/Geo/GeoScore.php',
            $root . '/src/Geo/GeoScoreCalculator.php',
            $root . '/src/Publish/PublishService.php',
        ];
        $keys = [];
        foreach ($files as $file) {
            preg_match_all('/(?:__|Translator::text|\$t)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $literal) {
                $keys[] = stripcslashes(substr($literal, 1, -1));
            }
        }
        self::assertGreaterThan(150, count($keys));
        return $keys;
    }
}
