#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Database\Connection;
use HolyMD\Config\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$pdo = (new Connection(Settings::fromEnvironment($root)))->pdo();
$token = bin2hex(random_bytes(16));
$pdo->beginTransaction();
try {
    $leaseExpired = "jobs.locked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE)";
    $exhaustedMessage = 'Worker lease expired after the final permitted attempt.';
    $pdo->exec("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'failed', builds.failure_message = COALESCE(builds.failure_message, " . $pdo->quote($exhaustedMessage) . ") WHERE jobs.status = 'running' AND jobs.attempts >= 3 AND {$leaseExpired}");
    $pdo->exec("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'failed', geo_reviews.failure_message = COALESCE(geo_reviews.failure_message, " . $pdo->quote($exhaustedMessage) . ") WHERE jobs.status = 'running' AND jobs.attempts >= 3 AND {$leaseExpired}");
    $pdo->exec("UPDATE jobs SET status = 'failed', last_error = COALESCE(last_error, " . $pdo->quote($exhaustedMessage) . "), locked_at = NULL, lock_token = NULL WHERE status = 'running' AND attempts >= 3 AND {$leaseExpired}");
    $pdo->exec("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'failed', builds.failure_message = COALESCE(builds.failure_message, " . $pdo->quote($exhaustedMessage) . ") WHERE jobs.status = 'queued' AND jobs.attempts >= 3");
    $pdo->exec("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'failed', geo_reviews.failure_message = COALESCE(geo_reviews.failure_message, " . $pdo->quote($exhaustedMessage) . ") WHERE jobs.status = 'queued' AND jobs.attempts >= 3");
    $pdo->exec("UPDATE jobs SET status = 'failed', last_error = COALESCE(last_error, " . $pdo->quote($exhaustedMessage) . "), locked_at = NULL, lock_token = NULL WHERE status = 'queued' AND attempts >= 3");
    $pdo->exec("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'queued', builds.started_at = NULL WHERE jobs.status = 'running' AND jobs.attempts < 3 AND {$leaseExpired}");
    $pdo->exec("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'queued' WHERE jobs.status = 'running' AND jobs.attempts < 3 AND {$leaseExpired}");
    $pdo->exec("UPDATE jobs SET status = 'queued', locked_at = NULL, lock_token = NULL, available_at = UTC_TIMESTAMP(6) WHERE status = 'running' AND attempts < 3 AND {$leaseExpired}");
    $job = $pdo->query("SELECT jobs.id, jobs.job_type, jobs.article_id, jobs.geo_review_id, jobs.build_id, jobs.action, jobs.attempts, articles.slug FROM jobs LEFT JOIN articles ON articles.id = jobs.article_id WHERE jobs.status = 'queued' AND jobs.attempts < 3 AND jobs.available_at <= UTC_TIMESTAMP(6) ORDER BY jobs.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if ($job === false) { $pdo->commit(); fwrite(STDOUT, "No queued jobs.\n"); exit(0); }
    $claim = $pdo->prepare("UPDATE jobs SET status = 'running', attempts = attempts + 1, locked_at = UTC_TIMESTAMP(6), lock_token = ? WHERE id = ? AND status = 'queued' AND attempts < 3");
    $claim->execute([$token, $job['id']]);
    if ($job['job_type'] === 'build' && $job['build_id'] !== null) { $started = $pdo->prepare("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'running', builds.started_at = UTC_TIMESTAMP(6), builds.completed_at = NULL, builds.failure_message = NULL WHERE builds.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?"); $started->execute([$job['build_id'], $job['id'], $token]); }
    if ($job['job_type'] === 'geo_review' && $job['geo_review_id'] !== null) { $started = $pdo->prepare("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'running', geo_reviews.completed_at = NULL, geo_reviews.failure_message = NULL WHERE geo_reviews.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?"); $started->execute([$job['geo_review_id'], $job['id'], $token]); }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Unable to claim job: ' . $exception->getMessage() . "\n");
    exit(1);
}

try {
    if (!is_string($job['slug'] ?? null)) throw new RuntimeException('Job has no linked article slug.');
    $entrypoint = $job['job_type'] === 'geo_review' ? $root . '/bin/holymd-geo-review.php' : $root . '/bin/holymd-build.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entrypoint) . ' --article ' . escapeshellarg($job['slug']);
    if ($job['job_type'] === 'build' && $job['action'] === 'withdraw') $command .= ' --withdraw';
    if ($job['job_type'] === 'geo_review' && $job['geo_review_id'] !== null) $command .= ' --review-id ' . escapeshellarg((string) $job['geo_review_id']);
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) throw new RuntimeException(($exitCode === 75 ? 'RETRYABLE: ' : 'PERMANENT: ') . implode("\n", $output));
    $pdo->beginTransaction();
    if ($job['build_id'] !== null) { $build = $pdo->prepare("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = 'succeeded', builds.completed_at = UTC_TIMESTAMP(6) WHERE builds.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?"); $build->execute([$job['build_id'], $job['id'], $token]); }
    if ($job['geo_review_id'] !== null) { $review = $pdo->prepare("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = 'completed', geo_reviews.completed_at = UTC_TIMESTAMP(6) WHERE geo_reviews.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?"); $review->execute([$job['geo_review_id'], $job['id'], $token]); }
    $done = $pdo->prepare("UPDATE jobs SET status = 'succeeded', locked_at = NULL, lock_token = NULL, last_error = NULL WHERE id = ? AND status = 'running' AND lock_token = ?");
    $done->execute([$job['id'], $token]);
    if ($done->rowCount() !== 1) throw new RuntimeException('Job lock was lost before completion.');
    $pdo->commit();
    fwrite(STDOUT, "Completed job {$job['id']}.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->beginTransaction();
    $retryable = str_starts_with($exception->getMessage(), 'RETRYABLE:');
    if ($job['build_id'] !== null) { $state = $pdo->prepare("UPDATE builds INNER JOIN jobs ON jobs.build_id = builds.id SET builds.status = IF(? AND jobs.attempts < 3, 'queued', 'failed'), builds.failure_message = ? WHERE builds.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?"); $state->execute([$retryable, substr($exception->getMessage(), 0, 1000), $job['build_id'], $job['id'], $token]); }
    if ($job['geo_review_id'] !== null) { $state = $pdo->prepare("UPDATE geo_reviews INNER JOIN jobs ON jobs.geo_review_id = geo_reviews.id SET geo_reviews.status = IF(? AND jobs.attempts < 3, 'queued', 'failed'), geo_reviews.failure_message = ? WHERE geo_reviews.id = ? AND jobs.id = ? AND jobs.status = 'running' AND jobs.lock_token = ?"); $state->execute([$retryable, substr($exception->getMessage(), 0, 1000), $job['geo_review_id'], $job['id'], $token]); }
    $failed = $pdo->prepare("UPDATE jobs SET status = IF(? AND attempts < 3, 'queued', 'failed'), available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), last_error = ?, locked_at = NULL, lock_token = NULL WHERE id = ? AND status = 'running' AND lock_token = ?");
    $failed->execute([$retryable, substr($exception->getMessage(), 0, 1000), $job['id'], $token]);
    $pdo->commit();
    fwrite(STDERR, "Job {$job['id']} failed safely: {$exception->getMessage()}\n");
    exit(1);
}
