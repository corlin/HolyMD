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
    $pdo->exec("UPDATE jobs SET status = 'queued', locked_at = NULL, lock_token = NULL, available_at = UTC_TIMESTAMP(6) WHERE status = 'running' AND locked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE)");
    $job = $pdo->query("SELECT jobs.id, jobs.job_type, jobs.article_id, jobs.geo_review_id, jobs.build_id, jobs.attempts, articles.slug FROM jobs LEFT JOIN articles ON articles.id = jobs.article_id WHERE jobs.status = 'queued' AND jobs.available_at <= UTC_TIMESTAMP(6) ORDER BY jobs.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if ($job === false) { $pdo->commit(); fwrite(STDOUT, "No queued jobs.\n"); exit(0); }
    $claim = $pdo->prepare("UPDATE jobs SET status = 'running', attempts = attempts + 1, locked_at = UTC_TIMESTAMP(6), lock_token = ? WHERE id = ? AND status = 'queued'");
    $claim->execute([$token, $job['id']]);
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
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) throw new RuntimeException(implode("\n", $output));
    $done = $pdo->prepare("UPDATE jobs SET status = 'succeeded', locked_at = NULL, lock_token = NULL, last_error = NULL WHERE id = ? AND status = 'running' AND lock_token = ?");
    $done->execute([$job['id'], $token]);
    if ($job['build_id'] !== null) { $build = $pdo->prepare("UPDATE builds SET status = 'succeeded', completed_at = UTC_TIMESTAMP(6) WHERE id = ?"); $build->execute([$job['build_id']]); }
    fwrite(STDOUT, "Completed job {$job['id']}.\n");
} catch (Throwable $exception) {
    $failed = $pdo->prepare("UPDATE jobs SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END, available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), last_error = ?, locked_at = NULL, lock_token = NULL WHERE id = ? AND status = 'running' AND lock_token = ?");
    $failed->execute([substr($exception->getMessage(), 0, 1000), $job['id'], $token]);
    fwrite(STDERR, "Job {$job['id']} failed safely: {$exception->getMessage()}\n");
    exit(1);
}
