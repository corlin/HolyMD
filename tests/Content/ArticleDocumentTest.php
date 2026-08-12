<?php

declare(strict_types=1);

namespace HolyMD\Tests\Content;

use HolyMD\Content\ArticleRepository;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
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

    public function test_write_creates_a_missing_articles_root_recursively(): void
    {
        $articlesRoot = $this->contentRoot . '/missing/articles';
        $document = new ArticleDocument(
            'new-note',
            'New note',
            "Body\n",
            new FrontMatter(['title' => 'New note', 'slug' => 'new-note', 'date' => '2026-08-12']),
            $articlesRoot . '/new-note.md',
        );

        (new ArticleRepository($articlesRoot))->write($document);

        self::assertSame("---\ntitle: New note\nslug: new-note\ndate: 2026-08-12\n---\nBody\n", file_get_contents($articlesRoot . '/new-note.md'));

        unlink($articlesRoot . '/new-note.md');
        rmdir($articlesRoot);
        rmdir(dirname($articlesRoot));
    }

    public function test_reads_lf_front_matter_when_the_body_contains_crlf(): void
    {
        file_put_contents(
            $this->contentRoot . '/mixed-endings.md',
            "---\ntitle: Mixed endings\nslug: mixed-endings\ndate: 2026-08-12\n---\nFirst line\r\n# Heading\r\n",
        );
        $repository = new ArticleRepository($this->contentRoot);

        $article = $repository->read('mixed-endings');

        self::assertSame("First line\r\n# Heading\r\n", $article->bodyMarkdown);
    }
}
