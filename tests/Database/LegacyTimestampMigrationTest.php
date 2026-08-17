<?php

declare(strict_types=1);

namespace HolyMD\Tests\Database;

use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class LegacyTimestampMigrationTest extends TestCase
{
    public function test_normalizes_only_default_generated_legacy_columns(): void
    {
        $pdo = $this->mysql();
        foreach (['admin_users', 'articles', 'article_versions', 'geo_reviews', 'geo_proposals', 'builds', 'jobs', 'audit_events', 'geo_scores'] as $table) {
            $pdo->exec("CREATE TEMPORARY TABLE `$table` (id INT PRIMARY KEY, created_at DATETIME(6), updated_at DATETIME(6) NULL)");
            $pdo->exec("INSERT INTO `$table` (id, created_at, updated_at) VALUES (1, '2026-08-17 20:00:00', '2026-08-17 20:05:00')");
        }
        $pdo->exec('SET @holymd_legacy_offset_seconds = 28800');

        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260817_normalize_legacy_timestamps.sql');
        self::assertIsString($sql);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            $pdo->exec(trim($statement));
        }

        foreach (['admin_users', 'articles', 'jobs'] as $table) {
            $row = $pdo->query("SELECT created_at, updated_at FROM `$table` WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('2026-08-17 12:00:00.000000', $row['created_at'], $table);
            self::assertSame('2026-08-17 12:05:00.000000', $row['updated_at'], $table);
        }
        foreach (['article_versions', 'geo_reviews', 'geo_proposals', 'builds', 'audit_events', 'geo_scores'] as $table) {
            $row = $pdo->query("SELECT created_at, updated_at FROM `$table` WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('2026-08-17 12:00:00.000000', $row['created_at'], $table);
            self::assertSame('2026-08-17 20:05:00.000000', $row['updated_at'], $table . ' unrelated column');
        }
    }

    private function mysql(): PDO
    {
        try {
            return (new Connection(Settings::fromEnvironment(dirname(__DIR__, 2))))->pdo();
        } catch (Throwable $exception) {
            self::markTestSkipped('A configured MySQL runtime is required: ' . $exception->getMessage());
        }
    }
}
