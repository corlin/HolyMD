<?php

declare(strict_types=1);

namespace HolyMD\Tests;

use DI\Container;
use HolyMD\Bootstrap;
use PDO;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\ConfiguredAiClient;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['HOLYMD_DSN', 'HOLYMD_DB_USERNAME', 'HOLYMD_DB_PASSWORD'] as $name) {
            $this->environment[$name] = getenv($name);
        }

        putenv('HOLYMD_DSN=sqlite::memory:');
        putenv('HOLYMD_DB_USERNAME');
        putenv('HOLYMD_DB_PASSWORD');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv(sprintf('%s=%s', $name, $value));
            }
        }

        $this->environment = [];
        parent::tearDown();
    }

    public function test_bootstrap_registers_a_pdo_with_exception_errors(): void
    {
        $container = Bootstrap::createContainer();

        self::assertInstanceOf(Container::class, $container);

        $pdo = $container->get(PDO::class);

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertInstanceOf(ConfiguredAiClient::class, $container->get(AiClient::class));
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
        self::assertStringContainsString('`failed_attempts`', $schema);
        self::assertStringContainsString('`is_active`', $schema);
    }
}
