<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lockDirectory = $root . '/content';
if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) { fwrite(STDERR, "Unable to create Cron lock directory.\n"); exit(1); }
$lock = fopen($lockDirectory . '/holymd-cron.lock', 'c');
if ($lock === false) { fwrite(STDERR, "Unable to open Cron lock.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDOUT, "Another HolyMD Cron run is active.\n"); exit(0); }
try {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/holymd-worker.php'), $exitCode);
    exit($exitCode);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
