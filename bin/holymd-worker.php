#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Database\Connection;
use HolyMD\Config\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$pdo = (new Connection(Settings::fromEnvironment($root)))->pdo();
$pdo->beginTransaction();
try {
    $job = $pdo->query("SELECT id, job_type, payload_json, attempts FROM holymd_jobs WHERE status = 'queued' AND available_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if ($job === false) { $pdo->commit(); fwrite(STDOUT, "No queued jobs.\n"); exit(0); }
    $claim = $pdo->prepare("UPDATE holymd_jobs SET status = 'running', attempts = attempts + 1, locked_at = UTC_TIMESTAMP() WHERE id = ?");
    $claim->execute([$job['id']]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Unable to claim job: ' . $exception->getMessage() . "\n");
    exit(1);
}

try {
    $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    if (($job['job_type'] ?? '') !== 'build' || !is_array($payload) || !is_string($payload['article_slug'] ?? null)) throw new RuntimeException('Unsupported job payload.');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/holymd-build.php') . ' --article ' . escapeshellarg($payload['article_slug']);
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) throw new RuntimeException(implode("\n", $output));
    $done = $pdo->prepare("UPDATE holymd_jobs SET status = 'completed', completed_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ? AND status = 'running'");
    $done->execute([$job['id']]);
    fwrite(STDOUT, "Completed job {$job['id']}.\n");
} catch (Throwable $exception) {
    $failed = $pdo->prepare("UPDATE holymd_jobs SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), last_error = ?, locked_at = NULL WHERE id = ? AND status = 'running'");
    $failed->execute([substr($exception->getMessage(), 0, 1000), $job['id']]);
    fwrite(STDERR, "Job {$job['id']} failed safely: {$exception->getMessage()}\n");
    exit(1);
}
