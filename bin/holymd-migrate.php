#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use HolyMD\Database\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
try {
    $pdo = (new Connection(Settings::fromEnvironment($root)))->pdo();
    $result = (new Migrator($pdo, $root))->migrate();
    fwrite(STDOUT, sprintf("PASS: database %s; %d migration(s) applied.\n", $result->installed ? 'installed' : 'checked', $result->applied));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
