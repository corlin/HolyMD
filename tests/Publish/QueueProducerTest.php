<?php
declare(strict_types=1);
namespace HolyMD\Tests\Publish;
use PHPUnit\Framework\TestCase;

final class QueueProducerTest extends TestCase
{
    public function test_queue_producers_create_linked_operational_records_transactionally(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Queue/MySqlJobQueue.php');
        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('INSERT INTO builds', $source);
        self::assertStringContainsString("'build', 'queued'", $source);
        self::assertStringContainsString('INSERT INTO geo_reviews', $source);
        self::assertStringContainsString("'geo_review', 'queued'", $source);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $source);
    }

    public function test_schema_has_idempotency_keys_and_publish_action_migration(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../../database/schema.sql');
        $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/20260812_queue_release_hardening.sql');
        self::assertStringContainsString('geo_reviews_request_unique', $schema);
        self::assertStringContainsString('geo_proposals_key_unique', $schema);
        self::assertStringContainsString("ENUM('publish', 'withdraw')", $migration);
    }
}
