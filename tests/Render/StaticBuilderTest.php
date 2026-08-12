<?php

declare(strict_types=1);

namespace HolyMD\Tests\Render;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Render\BuildInput;
use HolyMD\Render\StaticBuilder;
use PHPUnit\Framework\TestCase;

final class StaticBuilderTest extends TestCase
{
    private string $outputRoot;

    protected function setUp(): void
    {
        $this->outputRoot = sys_get_temp_dir() . '/holymd-build-' . bin2hex(random_bytes(6));
        mkdir($this->outputRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->outputRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->outputRoot);
    }

    public function test_builds_article_routes_and_truthful_discovery_documents(): void
    {
        $articles = [
            new ArticleDocument('first-post', 'First post', "# First\n\nBody.", new FrontMatter(['title' => 'First post', 'slug' => 'first-post', 'date' => '2026-08-12', 'summary' => 'One.', 'topics' => ['Notes']]), '/articles/first-post.md'),
            new ArticleDocument('second-post', 'Second post', 'Body two.', new FrontMatter(['title' => 'Second post', 'slug' => 'second-post', 'date' => '2026-08-11', 'topics' => ['Notes', 'Updates']]), '/articles/second-post.md'),
        ];

        $manifest = (new StaticBuilder())->build(new BuildInput($articles, 'HolyMD Notes', 'https://example.test', 'Ada Author', 'About Ada.', true), $this->outputRoot);

        self::assertSame(2, $manifest->articleCount);
        self::assertFileExists($this->outputRoot . '/articles/first-post/index.html');
        self::assertFileExists($this->outputRoot . '/feed.json');
        self::assertFileExists($this->outputRoot . '/rss.xml');
        self::assertFileExists($this->outputRoot . '/sitemap.xml');
        self::assertFileExists($this->outputRoot . '/llms.txt');
        $article = (string) file_get_contents($this->outputRoot . '/articles/first-post/index.html');
        self::assertStringContainsString('<article>', $article);
        self::assertStringContainsString('"@type":"Article"', $article);
        self::assertStringContainsString('https://example.test/articles/first-post/', $article);
        self::assertStringContainsString('Ada Author', (string) file_get_contents($this->outputRoot . '/feed.json'));
        self::assertStringContainsString('/articles/second-post/', (string) file_get_contents($this->outputRoot . '/sitemap.xml'));
        self::assertStringContainsString('/topics/notes/', (string) file_get_contents($this->outputRoot . '/sitemap.xml'));
        self::assertStringContainsString('og:title', $article);
        self::assertStringContainsString('<h1>First</h1>', $article);
    }

    public function test_closes_lists_and_quotes_before_following_blocks(): void
    {
        $article = new ArticleDocument('blocks', 'Blocks', "- item\n# Heading\n> quote\n## Subheading", new FrontMatter(['title' => 'Blocks', 'slug' => 'blocks', 'date' => '2026-08-12']), '/blocks');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/blocks/index.html');
        self::assertStringContainsString("<ul>\n<li>item</li>\n</ul>\n<h1>Heading</h1>\n<blockquote>", $html);
        self::assertStringContainsString("</blockquote>\n<h2>Subheading</h2>", $html);
    }

    public function test_closes_quote_before_list(): void
    {
        $article = new ArticleDocument('transitions', 'Transitions', "> quote\n- item", new FrontMatter(['title' => 'Transitions', 'slug' => 'transitions', 'date' => '2026-08-12']), '/transitions');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/transitions/index.html');
        self::assertStringContainsString("</blockquote>\n<ul>", $html);
    }

    public function test_rejects_duplicate_articles_and_topic_slug_collisions(): void
    {
        $one = new ArticleDocument('same', 'One', 'Body', new FrontMatter(['title' => 'One', 'slug' => 'same', 'date' => '2026-08-12']), '/one');
        $two = new ArticleDocument('same', 'Two', 'Body', new FrontMatter(['title' => 'Two', 'slug' => 'same', 'date' => '2026-08-11']), '/two');
        $this->expectException(\RuntimeException::class);
        (new StaticBuilder())->build(new BuildInput([$one, $two], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
    }

    public function test_rejects_topic_slug_collision_with_distinct_articles(): void
    {
        $one = new ArticleDocument('cpp', 'C++', 'Body', new FrontMatter(['title' => 'C++', 'slug' => 'cpp', 'date' => '2026-08-12', 'topics' => ['C++']]), '/cpp');
        $two = new ArticleDocument('csharp', 'C#', 'Body', new FrontMatter(['title' => 'C#', 'slug' => 'csharp', 'date' => '2026-08-11', 'topics' => ['C#']]), '/csharp');
        $this->expectException(\RuntimeException::class);
        (new StaticBuilder())->build(new BuildInput([$one, $two], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
    }
}
