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

        self::assertSame("---\ntitle: 'New note'\nslug: new-note\ndate: '2026-08-12'\n---\nBody\n", file_get_contents($articlesRoot . '/new-note.md'));

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

    public function test_unquoted_legacy_dates_stay_strings(): void
    {
        file_put_contents($this->contentRoot . '/legacy-date.md', "---\ntitle: Legacy date\nslug: legacy-date\ndate: 2026-08-12\nupdated: 2026-08-13\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);

        $article = $repository->read('legacy-date');

        self::assertSame('2026-08-12', $article->frontMatter->get('date'));
        self::assertSame('2026-08-13', $article->frontMatter->get('updated'));
    }

    public function test_parses_standard_yaml_types_comments_and_inline_lists(): void
    {
        file_put_contents($this->contentRoot . '/typed.md', "---\ntitle: Typed\nslug: typed\ndate: 2026-08-12\nreading_minutes: 5\ndraft: true\n# a comment\ntopics: [Notes, Health]\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);

        $article = $repository->read('typed');

        self::assertSame(5, $article->frontMatter->get('reading_minutes'));
        self::assertTrue($article->frontMatter->get('draft'));
        self::assertSame(['Notes', 'Health'], $article->frontMatter->get('topics'));
    }

    public function test_empty_lists_round_trip_as_empty_arrays(): void
    {
        $document = new ArticleDocument(
            'empty-topics',
            'Empty topics',
            "Body\n",
            new FrontMatter(['title' => 'Empty topics', 'slug' => 'empty-topics', 'date' => '2026-08-12', 'topics' => []]),
            $this->contentRoot . '/empty-topics.md',
        );
        $repository = new ArticleRepository($this->contentRoot);

        $repository->write($document);

        self::assertSame([], $repository->read('empty-topics')->frontMatter->get('topics'));
    }

    public function test_without_removes_a_key(): void
    {
        $frontMatter = new FrontMatter(['title' => 'T', 'summary' => 'S', 'date' => '2026-08-12']);

        self::assertArrayNotHasKey('summary', $frontMatter->without('summary')->all());
        self::assertSame(['title' => 'T', 'date' => '2026-08-12'], $frontMatter->without('summary')->all());
    }

    public function test_legacy_holymd_json_tag_parses_and_reserializes_natively(): void
    {
        file_put_contents($this->contentRoot . '/legacy.md', "---\ntitle: Legacy\nslug: legacy\ndate: 2026-08-12\nstructured_data: !holymd-json {\"@type\": \"FAQPage\", \"mainEntity\": []}\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);

        $article = $repository->read('legacy');
        self::assertSame(['@type' => 'FAQPage', 'mainEntity' => []], $article->frontMatter->get('structured_data'));

        $repository->write($article);
        $written = (string) file_get_contents($this->contentRoot . '/legacy.md');
        self::assertStringNotContainsString('!holymd-json', $written);
        self::assertSame(['@type' => 'FAQPage', 'mainEntity' => []], $repository->read('legacy')->frontMatter->get('structured_data'));
    }

    public function test_rejects_malformed_yaml_syntax(): void
    {
        file_put_contents($this->contentRoot . '/malformed.md', "---\ntitle: Broken\ntopics: [unclosed\n---\nBody\n");
        $repository = new ArticleRepository($this->contentRoot);

        $this->expectException(InvalidArgumentException::class);
        $repository->read('malformed');
    }

    public function test_round_trips_nested_geo_front_matter_values_without_warnings(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'Nested metadata',
            'slug' => 'nested-metadata',
            'date' => '2026-08-12',
            'structured_data' => [
                '@type' => 'FAQPage',
                'mainEntity' => [
                    ['@type' => 'Question', 'name' => 'What is HolyMD?'],
                ],
            ],
            'faq' => [
                ['question' => 'Does AI rewrite prose?', 'answer' => 'No.'],
            ],
        ]);
        $document = new ArticleDocument(
            'nested-metadata',
            'Nested metadata',
            "# Exact body\n",
            $frontMatter,
            $this->contentRoot . '/nested-metadata.md',
        );
        $repository = new ArticleRepository($this->contentRoot);

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $repository->write($document);
            $reloaded = $repository->read('nested-metadata');
        } finally {
            restore_error_handler();
        }

        self::assertSame($frontMatter->all(), $reloaded->frontMatter->all());
        self::assertSame("# Exact body\n", $reloaded->bodyMarkdown);
    }
}
