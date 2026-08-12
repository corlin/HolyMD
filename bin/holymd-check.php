#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use HolyMD\Deployment\Preflight;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
try {
    $settings = Settings::fromEnvironment($root);
    $pdo = (new Connection($settings))->pdo();
    $databaseReady = static function () use ($pdo): bool {
        return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('admin_users', 'jobs', 'schema_migrations')")->fetchColumn() === 3;
    };
    $names = ['HOLYMD_DSN', 'HOLYMD_SITE_NAME', 'HOLYMD_SITE_URL', 'HOLYMD_AUTHOR_NAME', 'HOLYMD_ABOUT', 'HOLYMD_SITE_LANGUAGE', 'HOLYMD_PUBLIC_TREE'];
    $environment = [];
    foreach ($names as $name) {
        $value = getenv($name);
        if (is_string($value)) $environment[$name] = $value;
    }
    $report = (new Preflight(null, $databaseReady))->check($root, $environment);
    fwrite($report->passed() ? STDOUT : STDERR, $report->text());
    exit($report->passed() ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: deployment check could not start: ' . $exception->getMessage() . "\n");
    exit(1);
}
