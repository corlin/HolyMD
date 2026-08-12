<?php

declare(strict_types=1);

namespace HolyMD\Tests\Deployment;

use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function test_shared_hosting_package_exposes_executable_checks_and_operational_guides(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['bin/holymd-check.php', 'bin/holymd-migrate.php', 'bin/holymd-prepare-release.php'] as $command) {
            self::assertFileIsReadable($root . '/' . $command);
            self::assertTrue(is_executable($root . '/' . $command), "{$command} must be executable.");
        }

        $deployment = file_get_contents($root . '/docs/operations/shared-hosting.md');
        $recovery = file_get_contents($root . '/docs/operations/backup-and-restore.md');
        self::assertIsString($deployment);
        self::assertIsString($recovery);
        foreach (['holymd-migrate.php', 'holymd-prepare-release.php', 'holymd-check.php', 'cron/holymd.php', 'DocumentRoot'] as $required) {
            self::assertStringContainsString($required, $deployment);
        }
        foreach (['content', 'mysqldump', 'holymd-migrate.php', 'SHA256SUMS'] as $required) {
            self::assertStringContainsString($required, $recovery);
        }
    }

    public function test_composer_declares_runtime_extensions_used_by_production_code(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['ext-pdo_mysql', 'ext-mbstring', 'ext-fileinfo', 'ext-gd', 'ext-exif', 'ext-sodium', 'ext-openssl', 'ext-json'] as $extension) {
            self::assertArrayHasKey($extension, $composer['require']);
        }
    }
}
