<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use PHPUnit\Framework\TestCase;

final class WorkerSchemaTest extends TestCase
{
    public function test_worker_claims_both_job_types_and_updates_linked_state_machines(): void
    {
        $worker = (string) file_get_contents(__DIR__ . '/../../src/Queue/Worker.php');
        $entrypoint = (string) file_get_contents(__DIR__ . '/../../bin/holymd-worker.php');

        self::assertStringContainsString('FROM jobs LEFT JOIN articles', $worker);
        self::assertStringContainsString('$job[\'job_type\'] === \'geo_review\'', $worker);
        self::assertStringContainsString("builds.status = 'running'", $worker);
        self::assertStringContainsString("geo_reviews.status = 'running'", $worker);
        self::assertStringContainsString("builds.status = IF", $worker);
        self::assertStringContainsString("geo_reviews.status = IF", $worker);
        self::assertStringContainsString('jobs.lock_token = ?', $worker);
        self::assertStringContainsString('private const LEASE_MINUTES = 15', $worker);
        self::assertStringContainsString('private const MAX_ATTEMPTS = 3', $worker);
        self::assertStringContainsString("SET builds.status = 'queued'", $worker);
        self::assertStringContainsString("SET geo_reviews.status = 'queued'", $worker);
        self::assertStringContainsString("status = 'succeeded'", $worker);
        self::assertStringNotContainsString('holymd_jobs', $worker);
        self::assertStringNotContainsString('payload_json', $worker);
        self::assertStringContainsString("exec(\$command . ' 2>&1'", $worker);
        self::assertStringContainsString("\$execution['exitCode'] === 75", $worker);
        self::assertStringContainsString("str_starts_with(\$exception->getMessage(), 'RETRYABLE:')", $worker);
        self::assertStringContainsString("? 1 : 0;", $worker);
        self::assertStringNotContainsString('$state->execute([$retryable,', $worker);
        self::assertStringNotContainsString('$failed->execute([$retryable,', $worker);
        self::assertStringContainsString("jobs.status = 'running' AND jobs.attempts >=", $worker);
        self::assertStringContainsString("jobs.status = 'running' AND jobs.attempts <", $worker);
        self::assertStringContainsString("jobs.status = 'queued' AND jobs.attempts <", $worker);
        self::assertStringContainsString("SET builds.status = 'failed'", $worker);
        self::assertStringContainsString("SET geo_reviews.status = 'failed'", $worker);
        self::assertStringContainsString('new Worker($pdo, $root)', $entrypoint);
        self::assertStringContainsString('->runOne()', $entrypoint);
        self::assertStringNotContainsString('FROM jobs', $entrypoint);
    }

    public function test_geo_entrypoint_runs_the_review_service_and_persists_proposals(): void
    {
        $entrypoint = (string) file_get_contents(__DIR__ . '/../../bin/holymd-geo-review.php');
        self::assertStringContainsString('GeoReviewService', $entrypoint);
        self::assertStringContainsString('INSERT INTO geo_proposals', $entrypoint);
        self::assertStringContainsString('GEO review input does not match its saved article checksum', $entrypoint);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $entrypoint);
        self::assertStringNotContainsString('GEO review queued for', $entrypoint);
    }
}
