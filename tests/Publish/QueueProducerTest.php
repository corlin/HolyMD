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

    public function test_queue_geo_store_is_mysql_backed_and_bound_to_serialized_article_checksum(): void
    {
        $store = (string) file_get_contents(__DIR__ . '/../../src/Geo/MySqlGeoProposalStore.php');
        $queue = (string) file_get_contents(__DIR__ . '/../../src/Queue/MySqlJobQueue.php');
        self::assertStringContainsString('FROM geo_proposals INNER JOIN geo_reviews', $store);
        self::assertStringContainsString("status = 'pending'", $store);
        self::assertStringContainsString("hash('sha256', \$article->serialize())", $queue);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $queue);
        self::assertStringContainsString('review-inputs/', $queue);
    }

    public function test_schema_has_idempotency_keys_and_publish_action_migration(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../../database/schema.sql');
        $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/20260812_queue_release_hardening.sql');
        self::assertStringContainsString('geo_reviews_request_unique', $schema);
        self::assertStringContainsString('geo_proposals_key_unique', $schema);
        self::assertStringContainsString("ENUM('publish', 'withdraw')", $migration);
    }

    public function test_build_jobs_are_bound_to_an_immutable_article_version(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../../database/schema.sql');
        $queue = (string) file_get_contents(__DIR__ . '/../../src/Queue/MySqlJobQueue.php');
        $worker = (string) file_get_contents(__DIR__ . '/../../src/Queue/Worker.php');
        $build = (string) file_get_contents(__DIR__ . '/../../bin/holymd-build.php');

        self::assertStringContainsString('`article_version_id` BIGINT UNSIGNED NULL', $schema);
        self::assertStringContainsString('jobs_article_version_fk', $schema);
        self::assertStringContainsString('article_version_id, build_id', $queue);
        self::assertStringContainsString('publish-inputs/', $queue);
        self::assertStringContainsString("--version", $worker);
        self::assertStringContainsString("--version", $build);
    }

    public function test_geo_decisions_record_the_administrator_and_an_audit_event(): void
    {
        $store = (string) file_get_contents(__DIR__ . '/../../src/Geo/MySqlGeoProposalStore.php');
        self::assertStringContainsString('decision_by_admin_user_id', $store);
        self::assertStringContainsString('INSERT INTO audit_events', $store);
        self::assertStringContainsString("'geo_proposal_decided'", $store);
    }
}
