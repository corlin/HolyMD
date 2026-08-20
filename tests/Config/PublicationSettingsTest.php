<?php

declare(strict_types=1);

namespace HolyMD\Tests\Config;

use HolyMD\Config\PublicationSettings;
use HolyMD\Render\BuildInput;
use PHPUnit\Framework\TestCase;

final class PublicationSettingsTest extends TestCase
{
    public function test_publication_settings_module_exists(): void
    {
        self::assertTrue(class_exists(PublicationSettings::class));
    }

    public function test_groups_public_identity_and_normalizes_the_base_path(): void
    {
        $settings = new PublicationSettings('Notes', 'https://example.test', 'Ada', 'About Ada', true, 'en-US', '/journal/');

        self::assertSame('/journal', $settings->basePath);
        self::assertSame([
            'site_name' => 'Notes',
            'site_url' => 'https://example.test',
            'author_name' => 'Ada',
            'about' => 'About Ada',
            'site_language' => 'en-US',
        ], $settings->adminValues());
    }

    public function test_reports_placeholder_identity_and_invalid_language(): void
    {
        $settings = new PublicationSettings('HolyMD', 'https://example.invalid', 'Author', '', false, 'invalid language');

        self::assertCount(4, $settings->validationErrors());
        self::assertStringContainsString('site URL', $settings->validationErrors()[0]);
        self::assertStringContainsString('site name', $settings->validationErrors()[1]);
        self::assertStringContainsString('author name', $settings->validationErrors()[2]);
        self::assertStringContainsString('language', $settings->validationErrors()[3]);
    }

    public function test_build_input_exposes_only_its_content_and_settings_inputs(): void
    {
        $settings = new PublicationSettings('Notes', 'https://example.test', 'Ada', 'About Ada', true, 'en-US', '/journal');

        $input = new BuildInput([], $settings);

        self::assertSame($settings, $input->settings);
        $publicProperties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass($input))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        sort($publicProperties);

        self::assertSame(['articles', 'builtAt', 'pages', 'settings'], $publicProperties);
    }
}
