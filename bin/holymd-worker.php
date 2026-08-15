#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Database\Connection;
use HolyMD\Config\Settings;
use HolyMD\Queue\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$pdo = (new Connection(Settings::fromEnvironment($root)))->pdo();
$result = (new Worker($pdo, $root))->runOne();
if ($result->stdout !== '') fwrite(STDOUT, $result->stdout);
if ($result->stderr !== '') fwrite(STDERR, $result->stderr);
exit($result->exitCode);
