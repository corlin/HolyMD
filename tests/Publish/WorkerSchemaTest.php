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
        self::assertStringContainsString("UPDATE builds SET status = 'running'", $worker);
        self::assertStringContainsString("UPDATE builds SET status = 'failed'", $worker);
        self::assertStringContainsString("UPDATE geo_reviews SET status = 'running'", $worker);
        self::assertStringContainsString("UPDATE geo_reviews SET status = 'failed'", $worker);
        self::assertStringContainsString('INTERVAL 15 MINUTE', $worker);
        self::assertStringContainsString("SET builds.status = 'queued'", $worker);
        self::assertStringContainsString("SET geo_reviews.status = 'queued'", $worker);
        self::assertStringContainsString("status = 'succeeded'", $worker);
        self::assertStringNotContainsString('holymd_jobs', $worker);
        self::assertStringNotContainsString('payload_json', $worker);
    }

    public function test_geo_entrypoint_runs_the_review_service_and_persists_proposals(): void
    {
        $entrypoint = (string) file_get_contents(__DIR__ . '/../../bin/holymd-geo-review.php');
        self::assertStringContainsString('GeoReviewService', $entrypoint);
        self::assertStringContainsString('save($proposal)', $entrypoint);
        self::assertStringContainsString('INSERT INTO geo_proposals', $entrypoint);
        self::assertStringNotContainsString('GEO review queued for', $entrypoint);
    }
}
