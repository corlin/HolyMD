<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\VersionService;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use PHPUnit\Framework\TestCase;

final class VersionServiceTest extends TestCase
{
    private string $root;
    private VersionService $service;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-versions-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->service = new VersionService($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function document(string $slug, string $body = "Body\n"): ArticleDocument
    {
        return new ArticleDocument($slug, 'Article ' . $slug, $body, new FrontMatter(['title' => 'Article ' . $slug, 'slug' => $slug, 'date' => '2026-08-12']), '/' . $slug);
    }

    public function test_snapshot_writes_a_slug_index(): void
    {
        $id = $this->service->snapshot($this->document('first'));

        self::assertFileExists($this->root . '/index.json');
        $index = json_decode((string) file_get_contents($this->root . '/index.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$id->value], $index['first']);
    }

    public function test_list_reads_from_the_index_and_orders_descending(): void
    {
        $first = $this->service->snapshot($this->document('first', "One\n"));
        $second = $this->service->snapshot($this->document('first', "Two\n"));
        $other = $this->service->snapshot($this->document('other'));

        self::assertSame([$second->value, $first->value], array_map(static fn ($id) => $id->value, $this->service->list('first')));
        self::assertSame([$other->value], array_map(static fn ($id) => $id->value, $this->service->list('other')));
    }

    public function test_list_falls_back_to_the_full_scan_without_an_index(): void
    {
        $id = $this->service->snapshot($this->document('first'));
        unlink($this->root . '/index.json');

        self::assertSame([$id->value], array_map(static fn ($id) => $id->value, $this->service->list('first')));
    }

    public function test_repeated_snapshots_do_not_duplicate_index_entries(): void
    {
        $this->service->snapshot($this->document('first'));
        $this->service->snapshot($this->document('first'));

        $index = json_decode((string) file_get_contents($this->root . '/index.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $index['first']);
    }

    public function test_indexed_missing_files_are_filtered_from_listings(): void
    {
        $id = $this->service->snapshot($this->document('first'));
        unlink($this->root . '/' . $id->value . '.md');

        self::assertSame([], $this->service->list('first'));
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
