<?php

declare(strict_types=1);

namespace HolyMD\Tests\Content;

use HolyMD\Content\ArticleRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArticleDocumentTest extends TestCase
{
    private string $contentRoot;

    protected function setUp(): void
    {
        $this->contentRoot = sys_get_temp_dir() . '/holymd-content-' . bin2hex(random_bytes(6));
        mkdir($this->contentRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->contentRoot . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->contentRoot);
    }

    public function test_reads_yaml_front_matter_and_preserves_the_markdown_body_byte_for_byte(): void
    {
        file_put_contents($this->contentRoot . '/hello-world.md', "---\ntitle: Hello world\nslug: hello-world\ndate: 2026-08-12\ntopics:\n  - Notes\n---\n# Exact body\n\nTrailing spaces  \n");
        $repository = new ArticleRepository($this->contentRoot);

        $article = $repository->read('hello-world');

        self::assertSame('Hello world', $article->title);
        self::assertSame('hello-world', $article->slug);
        self::assertSame(['Notes'], $article->frontMatter->get('topics'));
        self::assertSame("# Exact body\n\nTrailing spaces  \n", $article->bodyMarkdown);

        $repository->write($article->withFrontMatter($article->frontMatter->with('summary', 'A short note.')));

        self::assertStringEndsWith("---\n# Exact body\n\nTrailing spaces  \n", (string) file_get_contents($this->contentRoot . '/hello-world.md'));
    }

    public function test_rejects_missing_required_front_matter_and_unsafe_paths(): void
    {
        file_put_contents($this->contentRoot . '/missing.md', "---\ntitle: Missing fields\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);

        $this->expectException(InvalidArgumentException::class);
        $repository->read('../missing');
    }

    public function test_rejects_documents_without_title_slug_and_date(): void
    {
        file_put_contents($this->contentRoot . '/missing.md', "---\ntitle: Missing fields\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);

        $this->expectException(InvalidArgumentException::class);
        $repository->read('missing');
    }

    public function test_round_trips_quoted_backslashes(): void
    {
        file_put_contents($this->contentRoot . '/quoted.md', "---\ntitle: \"A \\\\ path\"\nslug: quoted\ndate: 2026-08-12\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);
        $article = $repository->read('quoted');
        self::assertSame('A \\ path', $article->title);
        $repository->write($article);
        self::assertSame('A \\ path', $repository->read('quoted')->title);
    }
}
