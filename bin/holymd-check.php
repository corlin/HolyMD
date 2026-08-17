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
        $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('admin_users','articles','article_versions','geo_reviews','geo_proposals','builds','jobs','audit_events','schema_migrations','geo_scores')")->fetchColumn();
        $columns = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='jobs' AND column_name='action') OR (table_name='article_versions' AND column_name='body_checksum') OR (table_name='geo_reviews' AND column_name='request_key') OR (table_name='geo_proposals' AND column_name='proposal_key'))")->fetchColumn();
        $indexes = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND ((table_name='geo_reviews' AND index_name='geo_reviews_request_unique') OR (table_name='geo_proposals' AND index_name='geo_proposals_key_unique'))")->fetchColumn();
        return $tables === 10 && $columns === 4 && $indexes === 2;
    };
    $names = ['HOLYMD_DSN', 'HOLYMD_SITE_NAME', 'HOLYMD_SITE_URL', 'HOLYMD_AUTHOR_NAME', 'HOLYMD_ABOUT', 'HOLYMD_SITE_LANGUAGE', 'HOLYMD_PUBLIC_TREE', 'HOLYMD_GEO_API_CREDENTIAL', 'HOLYMD_GEO_API_KEY'];
    $environment = [];
    foreach ($names as $name) {
        $value = \HolyMD\Config\Env::get($name);
        if (is_string($value)) $environment[$name] = $value;
    }
    $report = (new Preflight(null, $databaseReady))->check($root, $environment);
    fwrite($report->passed() ? STDOUT : STDERR, $report->text());
    exit($report->passed() ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: deployment check could not start: ' . $exception->getMessage() . "\n");
    exit(1);
}
