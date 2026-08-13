#!/usr/bin/env php
<?php
declare(strict_types=1);
use HolyMD\Publish\AtomicPublicTree;
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$pointer = (string) (\HolyMD\Config\Env::get('HOLYMD_PUBLIC_TREE') ?: $root . '/public/.holymd-current');
(new AtomicPublicTree())->prepare($pointer, $root . '/public/site');
fwrite(STDOUT, "PASS: static release pointer is prepared at {$pointer}.\n");
