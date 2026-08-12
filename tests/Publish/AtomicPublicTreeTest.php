<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use HolyMD\Publish\AtomicPublicTree;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AtomicPublicTreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-tree-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_swap_replaces_a_complete_tree_only_after_temporary_tree_exists(): void
    {
        mkdir($this->root . '/legacy');
        mkdir($this->root . '/temporary');
        file_put_contents($this->root . '/legacy/index.html', 'old');
        file_put_contents($this->root . '/temporary/index.html', 'new');

        $tree = new AtomicPublicTree();
        $tree->prepare($this->root . '/live', $this->root . '/legacy');
        $tree->swap($this->root . '/temporary', $this->root . '/live');

        self::assertSame('new', file_get_contents($this->root . '/live/index.html'));
        self::assertDirectoryDoesNotExist($this->root . '/temporary');
    }

    public function test_prepare_keeps_legacy_tree_visible_and_installing_over_a_directory_fails_closed(): void
    {
        mkdir($this->root . '/legacy');
        file_put_contents($this->root . '/legacy/index.html', 'old');
        $tree = new AtomicPublicTree();
        $tree->prepare($this->root . '/current', $this->root . '/legacy');
        self::assertSame('old', file_get_contents($this->root . '/current/index.html'));
        self::assertSame('old', file_get_contents($this->root . '/legacy/index.html'));
        mkdir($this->root . '/directory-pointer');
        mkdir($this->root . '/temporary');
        $this->expectExceptionMessage('must be prepared');
        $tree->swap($this->root . '/temporary', $this->root . '/directory-pointer');
    }

    public function test_swap_rejects_a_missing_temporary_tree_without_touching_live_tree(): void
    {
        mkdir($this->root . '/live');
        file_put_contents($this->root . '/live/index.html', 'old');

        $this->expectException(RuntimeException::class);
        try {
            (new AtomicPublicTree())->swap($this->root . '/missing', $this->root . '/live');
        } finally {
            self::assertSame('old', file_get_contents($this->root . '/live/index.html'));
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->remove($path . '/' . $entry);
        }
        rmdir($path);
    }
}
