<?php

declare(strict_types=1);

namespace HolyMD\Tests\Deployment;

use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function test_shared_hosting_package_exposes_executable_checks_and_operational_guides(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['bin/holymd-check.php', 'bin/holymd-migrate.php', 'bin/holymd-prepare-release.php', 'bin/holymd-backup.php'] as $command) {
            self::assertFileIsReadable($root . '/' . $command);
            self::assertTrue(is_executable($root . '/' . $command), "{$command} must be executable.");
        }

        $deployment = file_get_contents($root . '/docs/operations/shared-hosting.md');
        $recovery = file_get_contents($root . '/docs/operations/backup-and-restore.md');
        self::assertIsString($deployment);
        self::assertIsString($recovery);
        foreach (['holymd-migrate.php', 'holymd-prepare-release.php', 'holymd-check.php', 'cron/holymd.php', 'DocumentRoot', 'password-reset', 'holymd-admin.php list'] as $required) {
            self::assertStringContainsString($required, $deployment);
        }
        foreach (['content', 'mysqldump', 'holymd-migrate.php', 'SHA256SUMS', 'holymd-backup.php'] as $required) {
            self::assertStringContainsString($required, $recovery);
        }
    }

    public function test_backup_script_prints_usage_without_touching_the_environment(): void
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/holymd-backup.php', '--help'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertIsString($output);
        self::assertStringContainsString('Usage: holymd-backup.php', $output);
    }

    public function test_composer_declares_runtime_extensions_used_by_production_code(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['ext-pdo_mysql', 'ext-mbstring', 'ext-fileinfo', 'ext-gd', 'ext-exif', 'ext-openssl', 'ext-json', 'ext-curl'] as $extension) {
            self::assertArrayHasKey($extension, $composer['require']);
        }
    }

    public function test_geo_key_command_emits_decryptable_environment_assignments_without_echoing_plaintext(): void
    {
        $root = dirname(__DIR__, 2);
        $plain = 'sk-command-secret-that-must-not-appear';
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/holymd-admin.php', 'encrypt-geo-key'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['HOLYMD_GEO_PLAINTEXT_KEY' => $plain],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertIsString($output);
        self::assertStringNotContainsString($plain, $output);

        preg_match('/HOLYMD_GEO_API_CREDENTIAL="([^"]+)"/', $output, $credential);
        preg_match('/HOLYMD_GEO_API_KEY="([^"]+)"/', $output, $key);
        self::assertArrayHasKey(1, $credential);
        self::assertArrayHasKey(1, $key);
        $payload = base64_decode($credential[1], true);
        $decodedKey = base64_decode($key[1], true);
        self::assertIsString($payload);
        self::assertIsString($decodedKey);
        // AES-256-GCM layout: iv (12) || tag (16) || ciphertext.
        $decrypted = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            $decodedKey,
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
        );
        self::assertSame($plain, $decrypted);
    }
}
