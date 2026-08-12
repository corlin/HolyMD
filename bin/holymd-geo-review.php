#!/usr/bin/env php
<?php
declare(strict_types=1);

// GEO review jobs are intentionally delegated to the configured application service.
// This entrypoint gives Cron a stable, auditable command boundary.
require dirname(__DIR__) . '/vendor/autoload.php';
$slug = $argv[array_search('--article', $argv, true) + 1] ?? null;
if (!is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) { fwrite(STDERR, "A safe article slug is required.\n"); exit(64); }
fwrite(STDOUT, "GEO review queued for {$slug}; run through the configured GEO service.\n");
