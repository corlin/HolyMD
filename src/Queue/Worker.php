<?php

declare(strict_types=1);

namespace HolyMD\Queue;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class Worker
{
    private const MAX_ATTEMPTS = 3;
    private const LEASE_MINUTES = 15;

    /** @var Closure(string):array{exitCode:int,output:list<string>} */
    private Closure $executor;

    /** @param null|Closure(string):array{exitCode:int,output:list<string>} $executor */
    public function __construct(private PDO $pdo, private string $projectRoot, ?Closure $executor = null)
    {
        $this->executor = $executor ?? static function (string $command): array {
            exec($command . ' 2>&1', $output, $exitCode);
            return ['exitCode' => $exitCode, 'output' => $output];
        };
    }

    public function runOne(): WorkerResult
    {
        $token = bin2hex(random_bytes(16));
        try {
            $job = $this->claim($token);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return new WorkerResult(1, stderr: 'Unable to claim job: ' . $exception->getMessage() . "\n");
        }

        if ($job === null) return new WorkerResult(0, "No queued jobs.\n");

        try {
            $command = $this->command($job);
            $execution = ($this->executor)($command);
            if ($execution['exitCode'] !== 0) {
                $kind = $execution['exitCode'] === 75 ? 'RETRYABLE: ' : 'PERMANENT: ';
                throw new RuntimeException($kind . implode("\n", $execution['output']));
            }
            $this->complete($job, $token);
            return new WorkerResult(0, "Completed job {$job['id']}.\n");
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->fail($job, $token, $exception);
            return new WorkerResult(1, stderr: "Job {$job['id']} failed safely: {$exception->getMessage()}\n");
        }
    }

    /** @return array<string,mixed>|null */
    private function claim(string $token): ?array
    {
        $this->pdo->beginTransaction();
        $this->recoverExpiredLeases();
        $job = $this->pdo->query("SELECT jobs.id, jobs.job_type, jobs.article_id, jobs.article_version_id, jobs.geo_review_id, jobs.build_id, jobs.action, jobs.attempts, articles.slug, article_versions.snapshot_path FROM jobs LEFT JOIN articles ON articles.id = jobs.article_id LEFT JOIN article_versions ON article_versions.id = jobs.article_version_id WHERE jobs.status = 'queued' AND jobs.attempts < " . self::MAX_ATTEMPTS . " AND jobs.available_at <= UTC_TIMESTAMP(6) ORDER BY jobs.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
        if (!is_array($job)) {
            $this->pdo->commit();
            return null;
        }

        $claim = $this->pdo->prepare("UPDATE jobs SET status = 'running', attempts = attempts + 1, locked_at = UTC_TIMESTAMP(6), lock_token = ? WHERE id = ? AND status = 'queued' AND attempts < " . self::MAX_ATTEMPTS);
        $claim->execute([$token, $job['id']]);
        if ($job['job_type'] === 'build' && $job['build_id'] !== null) {
            $started = $this->pdo->prepare("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'running', builds.started_at = UTC_TIMESTAMP(6), builds.completed_at = NULL, builds.failure_message = NULL WHERE builds.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?");
            $started->execute([$job['build_id'], $job['id'], $token]);
        }
        if ($job['job_type'] === 'geo_review' && $job['geo_review_id'] !== null) {
            $started = $this->pdo->prepare("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'running', geo_reviews.completed_at = NULL, geo_reviews.failure_message = NULL WHERE geo_reviews.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?");
            $started->execute([$job['geo_review_id'], $job['id'], $token]);
        }
        $this->pdo->commit();
        return $job;
    }

    private function recoverExpiredLeases(): void
    {
        $leaseExpired = 'jobs.locked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ' . self::LEASE_MINUTES . ' MINUTE)';
        $message = $this->pdo->quote('Worker lease expired after the final permitted attempt.');
        $attempts = self::MAX_ATTEMPTS;

        $this->pdo->exec("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'failed', builds.failure_message = COALESCE(builds.failure_message, {$message}) WHERE jobs.status = 'running' AND jobs.attempts >= {$attempts} AND {$leaseExpired}");
        $this->pdo->exec("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'failed', geo_reviews.failure_message = COALESCE(geo_reviews.failure_message, {$message}) WHERE jobs.status = 'running' AND jobs.attempts >= {$attempts} AND {$leaseExpired}");
        $this->pdo->exec("UPDATE jobs SET status = 'failed', last_error = COALESCE(last_error, {$message}), locked_at = NULL, lock_token = NULL WHERE status = 'running' AND attempts >= {$attempts} AND {$leaseExpired}");
        $this->pdo->exec("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'failed', builds.failure_message = COALESCE(builds.failure_message, {$message}) WHERE jobs.status = 'queued' AND jobs.attempts >= {$attempts}");
        $this->pdo->exec("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'failed', geo_reviews.failure_message = COALESCE(geo_reviews.failure_message, {$message}) WHERE jobs.status = 'queued' AND jobs.attempts >= {$attempts}");
        $this->pdo->exec("UPDATE jobs SET status = 'failed', last_error = COALESCE(last_error, {$message}), locked_at = NULL, lock_token = NULL WHERE status = 'queued' AND attempts >= {$attempts}");
        $this->pdo->exec("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'queued', builds.started_at = NULL WHERE jobs.status = 'running' AND jobs.attempts < {$attempts} AND {$leaseExpired}");
        $this->pdo->exec("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'queued' WHERE jobs.status = 'running' AND jobs.attempts < {$attempts} AND {$leaseExpired}");
        $this->pdo->exec("UPDATE jobs SET status = 'queued', locked_at = NULL, lock_token = NULL, available_at = UTC_TIMESTAMP(6) WHERE status = 'running' AND attempts < {$attempts} AND {$leaseExpired}");
    }

    /** @param array<string,mixed> $job */
    private function command(array $job): string
    {
        if (!is_string($job['slug'] ?? null)) throw new RuntimeException('Job has no linked article slug.');
        $entrypoint = $job['job_type'] === 'geo_review' ? $this->projectRoot . '/bin/holymd-geo-review.php' : $this->projectRoot . '/bin/holymd-build.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entrypoint) . ' --article ' . escapeshellarg($job['slug']);
        if ($job['job_type'] === 'build' && $job['action'] === 'withdraw') $command .= ' --withdraw';
        if ($job['job_type'] === 'build' && $job['action'] === 'publish') {
            if (!is_string($job['snapshot_path'] ?? null)) throw new RuntimeException('Publish job has no immutable publication input.');
            $command .= ' --version ' . escapeshellarg((string) basename($job['snapshot_path'], '.md'));
        }
        if ($job['job_type'] === 'geo_review' && $job['geo_review_id'] !== null) $command .= ' --review-id ' . escapeshellarg((string) $job['geo_review_id']);
        return $command;
    }

    /** @param array<string,mixed> $job */
    private function complete(array $job, string $token): void
    {
        $this->pdo->beginTransaction();
        if ($job['build_id'] !== null) {
            $build = $this->pdo->prepare("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'succeeded', builds.completed_at = UTC_TIMESTAMP(6) WHERE builds.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?");
            $build->execute([$job['build_id'], $job['id'], $token]);
        }
        if ($job['geo_review_id'] !== null) {
            $review = $this->pdo->prepare("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'completed', geo_reviews.completed_at = UTC_TIMESTAMP(6) WHERE geo_reviews.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?");
            $review->execute([$job['geo_review_id'], $job['id'], $token]);
        }
        $done = $this->pdo->prepare("UPDATE jobs SET status = 'succeeded', locked_at = NULL, lock_token = NULL, last_error = NULL WHERE id = ? AND status = 'running' AND lock_token = ?");
        $done->execute([$job['id'], $token]);
        if ($done->rowCount() !== 1) throw new RuntimeException('Job lock was lost before completion.');
        $this->pdo->commit();
    }

    /** @param array<string,mixed> $job */
    private function fail(array $job, string $token, Throwable $exception): void
    {
        $this->pdo->beginTransaction();
        $retryableFlag = str_starts_with($exception->getMessage(), 'RETRYABLE:') ? 1 : 0;
        $message = substr($exception->getMessage(), 0, 1000);
        if ($job['build_id'] !== null) {
            $state = $this->pdo->prepare("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = IF(? AND jobs.attempts < " . self::MAX_ATTEMPTS . ", 'queued', 'failed'), builds.failure_message = ? WHERE builds.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?");
            $state->execute([$retryableFlag, $message, $job['build_id'], $job['id'], $token]);
        }
        if ($job['geo_review_id'] !== null) {
            $state = $this->pdo->prepare("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = IF(? AND jobs.attempts < " . self::MAX_ATTEMPTS . ", 'queued', 'failed'), geo_reviews.failure_message = ? WHERE geo_reviews.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?");
            $state->execute([$retryableFlag, $message, $job['geo_review_id'], $job['id'], $token]);
        }
        $failed = $this->pdo->prepare("UPDATE jobs SET status = IF(? AND attempts < " . self::MAX_ATTEMPTS . ", 'queued', 'failed'), available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), last_error = ?, locked_at = NULL, lock_token = NULL WHERE id = ? AND status = 'running' AND lock_token = ?");
        $failed->execute([$retryableFlag, $message, $job['id'], $token]);
        $this->pdo->commit();
    }
}
