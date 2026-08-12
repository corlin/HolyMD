<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use HolyMD\Content\ArticleRepository;
use HolyMD\Publish\ArticleId;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
use HolyMD\Render\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PublishServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-publish-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/articles', 0777, true);
        mkdir($this->root . '/public', 0777, true);
        file_put_contents($this->root . '/public/index.html', 'previous site');
        file_put_contents($this->root . '/public/.holymd-manifest.json', '{"build":"previous"}');
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nstatus: draft\n---\nBody\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_renderer_failure_keeps_live_tree_and_manifest_unchanged(): void
    {
        $service = $this->service(new StaticBuilder(new TemplateRenderer($this->root . '/missing-templates')));

        try {
            $service->publish(new ArticleId('first-note'));
            self::fail('Expected a renderer failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Template', $exception->getMessage());
        }

        self::assertSame('previous site', file_get_contents($this->root . '/public/index.html'));
        self::assertSame('{"build":"previous"}', file_get_contents($this->root . '/public/.holymd-manifest.json'));
        self::assertSame('draft', (new ArticleRepository($this->root . '/articles'))->read('first-note')->frontMatter->get('status'));
    }

    public function test_publish_generates_slug_redirects_and_excludes_withdrawn_articles_from_discovery(): void
    {
        file_put_contents($this->root . '/articles/renamed.md', "---\ntitle: Renamed\nslug: renamed\ndate: 2026-08-11\nstatus: published\nprevious_slugs:\n  - old-name\n---\nPublished\n");
        file_put_contents($this->root . '/articles/withdrawn.md', "---\ntitle: Withdrawn\nslug: withdrawn\ndate: 2026-08-10\nstatus: withdrawn\n---\nGone\n");

        $result = $this->service()->publish(new ArticleId('first-note'));

        self::assertSame(2, $result->manifest->articleCount);
        self::assertFileExists($this->root . '/public/articles/old-name/index.html');
        self::assertStringContainsString('/articles/renamed/', (string) file_get_contents($this->root . '/public/articles/old-name/index.html'));
        self::assertStringNotContainsString('withdrawn', (string) file_get_contents($this->root . '/public/feed.json'));
        self::assertStringNotContainsString('withdrawn', (string) file_get_contents($this->root . '/public/sitemap.xml'));
        self::assertSame('published', (new ArticleRepository($this->root . '/articles'))->read('first-note')->frontMatter->get('status'));
    }

    private function service(?StaticBuilder $builder = null): PublishService
    {
        return new PublishService(
            new ArticleRepository($this->root . '/articles'),
            $builder ?? new StaticBuilder(),
            new AtomicPublicTree(),
            $this->root . '/public',
            'HolyMD Notes',
            'https://example.test',
            'Ada Author',
            'About Ada.',
            true,
            $this->root . '/audit',
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) return;
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') continue;
            $childPath = $path . '/' . $child;
            is_dir($childPath) && !is_link($childPath) ? $this->removeDirectory($childPath) : unlink($childPath);
        }
        rmdir($path);
    }
}
