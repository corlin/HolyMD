<?php

declare(strict_types=1);

namespace HolyMD\Tests;

use DI\Container;
use HolyMD\Bootstrap;
use PDO;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('HOLYMD_DSN=sqlite::memory:');
        putenv('HOLYMD_DB_USERNAME');
        putenv('HOLYMD_DB_PASSWORD');
    }

    protected function tearDown(): void
    {
        putenv('HOLYMD_DSN');
        putenv('HOLYMD_DB_USERNAME');
        putenv('HOLYMD_DB_PASSWORD');
        parent::tearDown();
    }

    public function test_bootstrap_registers_a_pdo_with_exception_errors(): void
    {
        $container = Bootstrap::createContainer();

        self::assertInstanceOf(Container::class, $container);

        $pdo = $container->get(PDO::class);

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function test_operational_schema_defines_all_required_tables_without_article_bodies(): void
    {
        $schema = file_get_contents(__DIR__ . '/../database/schema.sql');

        self::assertNotFalse($schema);

        preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $schema, $matches);

        self::assertEqualsCanonicalizing(
            [
                'articles',
                'article_versions',
                'geo_reviews',
                'geo_proposals',
                'builds',
                'jobs',
                'admin_users',
                'audit_events',
            ],
            $matches[1],
        );
        self::assertDoesNotMatchRegularExpression('/\b(?:article_)?body\b/i', $schema);
    }
}
