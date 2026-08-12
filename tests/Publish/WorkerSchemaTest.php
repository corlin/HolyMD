<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use PHPUnit\Framework\TestCase;

final class WorkerSchemaTest extends TestCase
{
    public function test_worker_claims_both_job_types_and_updates_linked_state_machines(): void
    {
        $worker = (string) file_get_contents(__DIR__ . '/../../bin/holymd-worker.php');

        self::assertStringContainsString('FROM jobs LEFT JOIN articles', $worker);
        self::assertStringContainsString('$job[\'job_type\'] === \'geo_review\'', $worker);
        self::assertStringContainsString("builds.status = 'running'", $worker);
        self::assertStringContainsString("geo_reviews.status = 'running'", $worker);
        self::assertStringContainsString("builds.status = IF", $worker);
        self::assertStringContainsString("geo_reviews.status = IF", $worker);
        self::assertStringContainsString('jobs.lock_token = ?', $worker);
        self::assertStringContainsString('INTERVAL 15 MINUTE', $worker);
        self::assertStringContainsString("SET builds.status = 'queued'", $worker);
        self::assertStringContainsString("SET geo_reviews.status = 'queued'", $worker);
        self::assertStringContainsString("status = 'succeeded'", $worker);
        self::assertStringNotContainsString('holymd_jobs', $worker);
        self::assertStringNotContainsString('payload_json', $worker);
        self::assertStringContainsString("exec(\$command . ' 2>&1'", $worker);
        self::assertStringContainsString("\$exitCode === 75", $worker);
        self::assertStringContainsString("str_starts_with(\$exception->getMessage(), 'RETRYABLE:')", $worker);
    }

    public function test_geo_entrypoint_runs_the_review_service_and_persists_proposals(): void
    {
        $entrypoint = (string) file_get_contents(__DIR__ . '/../../bin/holymd-geo-review.php');
        self::assertStringContainsString('GeoReviewService', $entrypoint);
        self::assertStringContainsString('INSERT INTO geo_proposals', $entrypoint);
        self::assertStringContainsString('GEO review is not bound to the current saved article version checksum', $entrypoint);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $entrypoint);
        self::assertStringNotContainsString('GEO review queued for', $entrypoint);
    }
}
