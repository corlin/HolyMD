#!/usr/bin/env php
<?php
declare(strict_types=1);
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Admin\VersionService;
use HolyMD\Content\ArticleRepository;
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
\HolyMD\Config\Settings::fromEnvironment($root);
$pointer = (string) (\HolyMD\Config\Env::get('HOLYMD_PUBLIC_TREE') ?: $root . '/public/.holymd-current');
$pinned = (new VersionService($root . '/content/versions'))->pinPublished(new ArticleRepository($root . '/content/articles'));
(new AtomicPublicTree())->prepare($pointer, $root . '/public/site');
fwrite(STDOUT, "PASS: static release pointer is prepared at {$pointer}; {$pinned} published article version(s) pinned.\n");
