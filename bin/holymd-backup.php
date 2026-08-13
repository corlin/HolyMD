#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Config\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: holymd-backup.php\n\n"
    . "Creates backups/<UTC timestamp>/ with content.tar.gz, holymd.sql, env.copy and SHA256SUMS.\n"
    . "Run with cron or before deploys; verify with `sha256sum -c SHA256SUMS` inside the backup directory.\n";

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, $usage);
    exit(0);
}

$run = static function (string $command): int {
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, implode("\n", $output) . "\n");
    }
    return $code;
};

try {
    $root = dirname(__DIR__);
    umask(0077);
    $settings = Settings::fromEnvironment($root);
    $stamp = gmdate('Ymd\THis\Z');
    $target = $root . '/backups/' . $stamp;
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        fwrite(STDERR, "Unable to create the backup directory.\n");
        exit(1);
    }

    foreach (['tar', 'mysqldump', 'sha256sum'] as $tool) {
        exec('command -v ' . escapeshellarg($tool) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            fwrite(STDERR, "Required tool is missing: {$tool}.\n");
            exit(1);
        }
    }

    if ($run('tar -czf ' . escapeshellarg($target . '/content.tar.gz') . ' -C ' . escapeshellarg($root) . ' content') !== 0) {
        fwrite(STDERR, "Content archive failed; partial output kept in {$target}.\n");
        exit(1);
    }
    if (!copy($root . '/.env', $target . '/env.copy')) {
        fwrite(STDERR, "Unable to copy .env; partial output kept in {$target}.\n");
        exit(1);
    }

    $parts = Settings::mysqlParts($settings->dsn);
    if ($settings->username === null || $settings->username === '') {
        fwrite(STDERR, "HOLYMD_DB_USERNAME is required for the database dump.\n");
        exit(1);
    }
    $connection = [];
    if ($parts['socket'] !== null) {
        $connection[] = '--socket=' . escapeshellarg($parts['socket']);
    } else {
        if ($parts['host'] !== null && $parts['host'] !== '') {
            $connection[] = '-h ' . escapeshellarg($parts['host']);
        }
        if ($parts['port'] !== null && $parts['port'] !== '') {
            $connection[] = '-P ' . escapeshellarg($parts['port']);
        }
    }
    // MYSQL_PWD keeps the password out of the process argument list.
    $previousPassword = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . ($settings->password ?? ''));
    $code = $run('mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 ' . implode(' ', $connection) . ' -u ' . escapeshellarg($settings->username) . ' ' . escapeshellarg($parts['dbname']) . ' > ' . escapeshellarg($target . '/holymd.sql'));
    if ($previousPassword === false) {
        putenv('MYSQL_PWD');
    } else {
        putenv('MYSQL_PWD=' . $previousPassword);
    }
    if ($code !== 0) {
        fwrite(STDERR, "Database dump failed; partial output kept in {$target}.\n");
        exit(1);
    }

    if ($run('cd ' . escapeshellarg($target) . ' && sha256sum content.tar.gz holymd.sql env.copy > SHA256SUMS') !== 0) {
        fwrite(STDERR, "Checksum manifest failed; partial output kept in {$target}.\n");
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Backup failed: ' . $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Backup complete: {$target}\n");
foreach (['content.tar.gz', 'holymd.sql', 'env.copy', 'SHA256SUMS'] as $file) {
    fwrite(STDOUT, '  ' . $file . "\n");
}
exit(0);
