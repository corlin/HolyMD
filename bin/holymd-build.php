#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Admin\VersionId;
use HolyMD\Admin\VersionService;
use HolyMD\Config\PublicationSettings;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
\HolyMD\Config\Settings::fromEnvironment($root);
$articles = new ArticleRepository($root . '/content/articles');
$dryRun = in_array('--dry-run', $argv, true);
$rebuild = in_array('--rebuild', $argv, true);
$articleIndex = array_search('--article', $argv, true);
$slug = $articleIndex === false ? null : ($argv[$articleIndex + 1] ?? null);
$withdraw = in_array('--withdraw', $argv, true);
$versionIndex = array_search('--version', $argv, true);
$versionValue = $versionIndex === false ? null : ($argv[$versionIndex + 1] ?? null);
$publication = PublicationSettings::fromEnvironment();
$pages = new \HolyMD\Content\PageRepository($root . '/content/pages');

if (!$dryRun && !$rebuild && !is_string($slug)) {
    fwrite(STDERR, "Usage: holymd-build.php --dry-run | --rebuild | --article <slug>\n");
    exit(64);
}

$published = array_values(array_filter($articles->all(), static fn (ArticleDocument $article): bool => $article->frontMatter->get('status') === 'published'));
if ($dryRun) {
    $temporary = sys_get_temp_dir() . '/holymd-dry-run-' . bin2hex(random_bytes(6));
    try {
        $manifest = (new StaticBuilder())->build(new \HolyMD\Render\BuildInput($published, $publication, pages: $pages->all()), $temporary);
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

$versions = new VersionService($root . '/content/versions');
$service = new PublishService($articles, new StaticBuilder(), new AtomicPublicTree(), (string) (\HolyMD\Config\Env::get('HOLYMD_PUBLIC_TREE') ?: $root . '/public/.holymd-current'), $publication, $root . '/content/audit', null, $root . '/content/holymd-publish.lock', $versions, $pages);

if ($rebuild) {
    $result = $service->rebuild();
    fwrite(STDOUT, $result->validation->text() . "\nRebuilt site with {$result->manifest->articleCount} published article(s) and " . count($result->manifest->files) . " file(s).\n");
    exit(0);
}

if (!$withdraw && (!is_string($versionValue) || preg_match('/^[a-f0-9]{32}$/', $versionValue) !== 1)) { fwrite(STDERR, "A publish build requires --version <snapshot-id>.\n"); exit(64); }
$result = $withdraw ? $service->withdraw(new \HolyMD\Publish\ArticleId($slug)) : $service->publish(new \HolyMD\Publish\ArticleId($slug), new VersionId($versionValue));
fwrite(STDOUT, $result->validation->text() . "\nPublished {$result->manifest->articleCount} article(s).\n");
