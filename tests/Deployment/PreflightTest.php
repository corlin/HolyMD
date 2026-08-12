<?php

declare(strict_types=1);

namespace HolyMD\Tests\Deployment;

use HolyMD\Deployment\Preflight;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase
{
    public function test_reports_actionable_shared_hosting_failures(): void
    {
        $root = sys_get_temp_dir() . '/holymd-preflight-' . bin2hex(random_bytes(5));
        mkdir($root . '/content/articles', 0775, true);
        mkdir($root . '/content/media', 0775, true);
        mkdir($root . '/content/versions', 0775, true);
        mkdir($root . '/content/audit', 0775, true);
        mkdir($root . '/public/site', 0775, true);

        try {
            $report = (new Preflight(
                static fn (string $extension): bool => $extension !== 'gd',
                static fn (): bool => false,
            ))->check($root, [
                'HOLYMD_DSN' => 'mysql:host=localhost;dbname=holymd;charset=utf8mb4',
                'HOLYMD_SITE_NAME' => 'REPLACE_WITH_PUBLICATION_NAME',
                'HOLYMD_SITE_URL' => 'https://REPLACE_WITH_YOUR_DOMAIN',
                'HOLYMD_AUTHOR_NAME' => 'REPLACE_WITH_AUTHOR_NAME',
                'HOLYMD_ABOUT' => 'REPLACE_WITH_AUTHOR_BIOGRAPHY',
                'HOLYMD_SITE_LANGUAGE' => 'zh-CN',
            ]);

            self::assertFalse($report->passed());
            self::assertStringContainsString('PHP extension gd', $report->text());
            self::assertStringContainsString('public identity', $report->text());
            self::assertStringContainsString('MySQL connection', $report->text());
            self::assertStringContainsString('release pointer', $report->text());
        } finally {
            rmdir($root . '/content/articles');
            rmdir($root . '/content/media');
            rmdir($root . '/content/versions');
            rmdir($root . '/content/audit');
            rmdir($root . '/content');
            rmdir($root . '/public/site');
            rmdir($root . '/public');
            rmdir($root);
        }
    }

    public function test_passes_when_runtime_identity_storage_database_and_pointer_are_ready(): void
    {
        $root = sys_get_temp_dir() . '/holymd-preflight-' . bin2hex(random_bytes(5));
        mkdir($root . '/content/articles', 0775, true);
        mkdir($root . '/content/media', 0775, true);
        mkdir($root . '/content/versions', 0775, true);
        mkdir($root . '/content/audit', 0775, true);
        mkdir($root . '/public/site', 0775, true);
        symlink($root . '/public/site', $root . '/public/.holymd-current');

        try {
            $report = (new Preflight(static fn (): bool => true, static fn (): bool => true))->check($root, [
                'HOLYMD_DSN' => 'mysql:host=localhost;dbname=holymd;charset=utf8mb4',
                'HOLYMD_SITE_NAME' => 'Corlin Notes',
                'HOLYMD_SITE_URL' => 'https://notes.example.org',
                'HOLYMD_AUTHOR_NAME' => 'Corlin',
                'HOLYMD_ABOUT' => 'Independent maker and writer.',
                'HOLYMD_SITE_LANGUAGE' => 'zh-CN',
            ]);

            self::assertTrue($report->passed(), $report->text());
            self::assertStringContainsString('PASS', $report->text());
        } finally {
            unlink($root . '/public/.holymd-current');
            rmdir($root . '/content/articles');
            rmdir($root . '/content/media');
            rmdir($root . '/content/versions');
            rmdir($root . '/content/audit');
            rmdir($root . '/content');
            rmdir($root . '/public/site');
            rmdir($root . '/public');
            rmdir($root);
        }
    }

    public function test_rejects_publish_placeholders_and_missing_runtime_directories_without_requiring_geo_transport(): void
    {
        $root = sys_get_temp_dir() . '/holymd-preflight-' . bin2hex(random_bytes(5));
        mkdir($root . '/content/articles', 0775, true);
        mkdir($root . '/content/media', 0775, true);
        mkdir($root . '/public/site', 0775, true);
        symlink($root . '/public/site', $root . '/public/.holymd-current');
        try {
            $report = (new Preflight(static fn (): bool => true, static fn (): bool => true, static fn (): bool => false))->check($root, [
                'HOLYMD_DSN' => 'mysql:host=localhost;dbname=holymd',
                'HOLYMD_SITE_NAME' => 'HolyMD',
                'HOLYMD_SITE_URL' => 'https://example.com',
                'HOLYMD_AUTHOR_NAME' => 'Author',
                'HOLYMD_ABOUT' => 'Biography',
                'HOLYMD_SITE_LANGUAGE' => 'zh-CN',
            ]);
            self::assertFalse($report->passed());
            self::assertStringContainsString('content/versions', $report->text());
            self::assertStringContainsString('content/audit', $report->text());
            self::assertStringContainsString('public identity', $report->text());
            self::assertStringNotContainsString('allow_url_fopen', $report->text());
        } finally {
            unlink($root . '/public/.holymd-current');
            rmdir($root . '/content/articles');
            rmdir($root . '/content/media');
            rmdir($root . '/content');
            rmdir($root . '/public/site');
            rmdir($root . '/public');
            rmdir($root);
        }
    }
}
