#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$articles = new ArticleRepository($root . '/content/articles');
$dryRun = in_array('--dry-run', $argv, true);
$articleIndex = array_search('--article', $argv, true);
$slug = $articleIndex === false ? null : ($argv[$articleIndex + 1] ?? null);
$withdraw = in_array('--withdraw', $argv, true);

if (!$dryRun && !is_string($slug)) {
    fwrite(STDERR, "Usage: holymd-build.php --dry-run | --article <slug>\n");
    exit(64);
}

$published = array_values(array_filter($articles->all(), static fn (ArticleDocument $article): bool => $article->frontMatter->get('status') === 'published'));
if ($dryRun) {
    $temporary = sys_get_temp_dir() . '/holymd-dry-run-' . bin2hex(random_bytes(6));
    try {
        $manifest = (new StaticBuilder())->build(new \HolyMD\Render\BuildInput($published, (string) (getenv('HOLYMD_SITE_NAME') ?: 'HolyMD'), (string) (getenv('HOLYMD_SITE_URL') ?: 'https://example.invalid'), (string) (getenv('HOLYMD_AUTHOR_NAME') ?: 'Author'), (string) (getenv('HOLYMD_ABOUT') ?: ''), getenv('HOLYMD_LLMS_TXT') === '1'), $temporary);
        fwrite(STDOUT, "PASS: dry-run build rendered {$manifest->articleCount} published article(s) and " . count($manifest->files) . " file(s).\n");
    } finally {
        if (is_dir($temporary)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            rmdir($temporary);
        }
    }
    exit(0);
}

$service = new PublishService($articles, new StaticBuilder(), new AtomicPublicTree(), (string) (getenv('HOLYMD_PUBLIC_TREE') ?: $root . '/public/.holymd-current'), (string) (getenv('HOLYMD_SITE_NAME') ?: 'HolyMD'), (string) (getenv('HOLYMD_SITE_URL') ?: 'https://example.invalid'), (string) (getenv('HOLYMD_AUTHOR_NAME') ?: 'Author'), (string) (getenv('HOLYMD_ABOUT') ?: ''), getenv('HOLYMD_LLMS_TXT') === '1', $root . '/content/audit', null, $root . '/content/holymd-publish.lock');
$result = $withdraw ? $service->withdraw(new \HolyMD\Publish\ArticleId($slug)) : $service->publish(new \HolyMD\Publish\ArticleId($slug));
fwrite(STDOUT, $result->validation->text() . "\nPublished {$result->manifest->articleCount} article(s).\n");
