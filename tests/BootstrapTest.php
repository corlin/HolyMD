<?php

declare(strict_types=1);

namespace HolyMD\Tests;

use DI\Container;
use HolyMD\Bootstrap;
use HolyMD\Config\Env;
use PDO;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\ConfiguredAiClient;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['HOLYMD_DSN', 'HOLYMD_DB_USERNAME', 'HOLYMD_DB_PASSWORD'] as $name) {
            $this->environment[$name] = Env::get($name);
        }

        Env::set('HOLYMD_DSN', 'sqlite::memory:');
        Env::set('HOLYMD_DB_USERNAME', null);
        Env::set('HOLYMD_DB_PASSWORD', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            Env::set($name, $value);
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
                'geo_scores',
                'ai_bot_visits',
                'ai_referrals',
            ],
            $matches[1],
        );
        self::assertDoesNotMatchRegularExpression('/\b(?:article_)?body\b/i', $schema);
        self::assertStringContainsString('`failed_attempts`', $schema);
        self::assertStringContainsString('`is_active`', $schema);
    }

    public function test_schema_contains_every_table_created_by_a_migration(): void
    {
        // Fresh installs load schema.sql and skip migrations, so the two must not drift.
        $schema = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
        foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $migration) {
            preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', (string) file_get_contents($migration), $matches);
            foreach ($matches[1] as $table) {
                self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $schema, basename($migration) . ' creates a table missing from schema.sql');
            }
        }
    }
}
