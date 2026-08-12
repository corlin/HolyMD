<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use PHPUnit\Framework\TestCase;

final class WorkerSchemaTest extends TestCase
{
    public function test_worker_claims_only_build_jobs_using_the_operational_jobs_schema(): void
    {
        $worker = (string) file_get_contents(__DIR__ . '/../../bin/holymd-worker.php');

        self::assertStringContainsString('FROM jobs LEFT JOIN articles', $worker);
        self::assertStringContainsString("jobs.job_type = 'build'", $worker);
        self::assertStringContainsString("status = 'succeeded'", $worker);
        self::assertStringNotContainsString('holymd_jobs', $worker);
        self::assertStringNotContainsString('payload_json', $worker);
    }
}
