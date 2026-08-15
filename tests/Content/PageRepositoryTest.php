<?php

declare(strict_types=1);

namespace HolyMD\Tests\Content;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PageRepositoryTest extends TestCase
{
    private string $pagesRoot;

    protected function setUp(): void
    {
        $this->pagesRoot = sys_get_temp_dir() . '/holymd-pages-' . bin2hex(random_bytes(6));
        mkdir($this->pagesRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->pagesRoot . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->pagesRoot)) {
            rmdir($this->pagesRoot);
        }
    }

    public function test_writes_reads_and_deletes_pages(): void
    {
        $repo = new ArticleRepository($this->pagesRoot, ArticleRepository::RESERVED_PAGE_SLUGS);
        $doc = new ArticleDocument('privacy-policy', 'Privacy Policy', '# Privacy Policy Content', new FrontMatter(['title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'date' => '2026-08-14', 'nav_order' => 1]), $this->pagesRoot . '/privacy-policy.md');

        $repo->write($doc);
        self::assertTrue($repo->exists('privacy-policy'));

        $read = $repo->read('privacy-policy');
        self::assertSame('Privacy Policy', $read->title);
        self::assertSame('privacy-policy', $read->slug);
        self::assertSame(1, $read->frontMatter->get('nav_order'));

        $all = $repo->all();
        self::assertCount(1, $all);

        $repo->delete('privacy-policy');
        self::assertFalse($repo->exists('privacy-policy'));
    }

    public function test_rejects_reserved_slugs(): void
    {
        $repo = new ArticleRepository($this->pagesRoot, ArticleRepository::RESERVED_PAGE_SLUGS);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        $doc = new ArticleDocument('admin', 'Admin Page', 'Body', new FrontMatter(['title' => 'Admin Page', 'slug' => 'admin', 'date' => '2026-08-14']), $this->pagesRoot . '/admin.md');
        $repo->write($doc);
    }

    public function test_allows_about_slug_for_custom_pages(): void
    {
        $repo = new ArticleRepository($this->pagesRoot, ArticleRepository::RESERVED_PAGE_SLUGS);
        $doc = new ArticleDocument('about', 'About Me', '# About Content', new FrontMatter(['title' => 'About Me', 'slug' => 'about', 'date' => '2026-08-14', 'nav_order' => 99]), $this->pagesRoot . '/about.md');
        $repo->write($doc);
        self::assertTrue($repo->exists('about'));
        self::assertSame('About Me', $repo->read('about')->title);
    }
}
