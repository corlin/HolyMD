<?php

declare(strict_types=1);

namespace HolyMD\Tests\Config;

use HolyMD\Config\Settings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsTest extends TestCase
{
    public function test_mysql_dsn_parses_host_port_and_dbname(): void
    {
        $parts = Settings::mysqlParts('mysql:host=localhost;port=3307;dbname=holymd');

        self::assertSame('localhost', $parts['host']);
        self::assertSame('3307', $parts['port']);
        self::assertSame('holymd', $parts['dbname']);
        self::assertNull($parts['socket']);
    }

    public function test_mysql_dsn_parses_a_unix_socket(): void
    {
        $parts = Settings::mysqlParts('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=holymd');

        self::assertSame('/var/run/mysqld/mysqld.sock', $parts['socket']);
        self::assertSame('holymd', $parts['dbname']);
    }

    public function test_mysql_dsn_ignores_unknown_options(): void
    {
        $parts = Settings::mysqlParts('mysql:host=db.internal;charset=utf8mb4;dbname=holymd');

        self::assertSame('db.internal', $parts['host']);
        self::assertSame('holymd', $parts['dbname']);
    }

    public function test_mysql_dsn_requires_a_database_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database name');

        Settings::mysqlParts('mysql:host=localhost');
    }

    public function test_non_mysql_dsn_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MySQL DSN');

        Settings::mysqlParts('sqlite::memory:');
    }
}
