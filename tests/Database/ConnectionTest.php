<?php

declare(strict_types=1);

namespace HolyMD\Tests\Database;

use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ConnectionTest extends TestCase
{
    public function test_mysql_sessions_use_utc_and_preserve_the_legacy_offset(): void
    {
        try {
            $settings = Settings::fromEnvironment(dirname(__DIR__, 2));
            $pdo = (new Connection($settings))->pdo();
        } catch (Throwable $exception) {
            self::markTestSkipped('A configured MySQL runtime is required: ' . $exception->getMessage());
        }

        $row = $pdo->query("SELECT @@session.time_zone AS session_tz, @holymd_legacy_offset_seconds AS legacy_offset")->fetch();

        self::assertSame('+00:00', $row['session_tz']);
        self::assertIsInt((int) $row['legacy_offset']);
        self::assertGreaterThanOrEqual(-50400, (int) $row['legacy_offset']);
        self::assertLessThanOrEqual(50400, (int) $row['legacy_offset']);
    }
}
