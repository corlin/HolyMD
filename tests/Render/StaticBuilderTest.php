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
        self::assertFileExists($this->outputRoot . '/llms-full.txt');
        $article = (string) file_get_contents($this->outputRoot . '/articles/first-post/index.html');
        self::assertStringContainsString('<article>', $article);
        self::assertStringContainsString('"@type":"Article"', $article);
        self::assertStringContainsString('https://example.test/articles/first-post/', $article);
        self::assertStringContainsString('Ada Author', (string) file_get_contents($this->outputRoot . '/feed.json'));
        self::assertStringContainsString('/articles/second-post/', (string) file_get_contents($this->outputRoot . '/sitemap.xml'));
        self::assertStringContainsString('/topics/notes/', (string) file_get_contents($this->outputRoot . '/sitemap.xml'));
        self::assertStringContainsString('og:title', $article);
        self::assertStringContainsString('<html lang="zh-CN">', $article);
        self::assertStringContainsString('<h2 id="first">First</h2>', $article);
        self::assertStringContainsString('1 min read', $article);
        self::assertStringContainsString('class="theme-switcher"', $article);
        self::assertStringContainsString('LLMs-Full-Txt:', (string) file_get_contents($this->outputRoot . '/robots.txt'));
        self::assertStringContainsString('First post', (string) file_get_contents($this->outputRoot . '/llms-full.txt'));
        self::assertSame(1, substr_count($article, '<h1'));
    }

    public function test_closes_lists_and_quotes_before_following_blocks(): void
    {
        $article = new ArticleDocument('blocks', 'Blocks', "- item\n# Heading\n> quote\n## Subheading", new FrontMatter(['title' => 'Blocks', 'slug' => 'blocks', 'date' => '2026-08-12']), '/blocks');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/blocks/index.html');
        self::assertStringContainsString("<ul>\n<li>item</li>\n</ul>\n<h2 id=\"heading\">Heading</h2>\n<blockquote>", $html);
        self::assertStringContainsString("</blockquote>\n<h3 id=\"subheading\">Subheading</h3>", $html);
        self::assertSame(1, substr_count($html, '<h1'));
    }

    public function test_closes_quote_before_list(): void
    {
        $article = new ArticleDocument('transitions', 'Transitions', "> quote\n- item", new FrontMatter(['title' => 'Transitions', 'slug' => 'transitions', 'date' => '2026-08-12']), '/transitions');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/transitions/index.html');
        self::assertStringContainsString("</blockquote>\n<ul>", $html);
    }

    public function test_renders_visible_sources_and_propagates_them_to_json_ld(): void
    {
        $article = new ArticleDocument('sourced', 'Sourced', 'Evidence.', new FrontMatter(['title' => 'Sourced', 'slug' => 'sourced', 'date' => '2026-08-12', 'sources' => ['https://example.org/evidence']]), '/sourced');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/sourced/index.html');
        self::assertStringContainsString('<section aria-labelledby="sources-heading">', $html);
        self::assertStringContainsString('https://example.org/evidence', $html);
        self::assertStringContainsString('"citation":["https://example.org/evidence"]', $html);
    }

    public function test_renders_a_personal_brand_reading_experience_with_static_styles(): void
    {
        $articles = [
            new ArticleDocument('featured', 'Featured essay', 'A considered opening.', new FrontMatter(['title' => 'Featured essay', 'slug' => 'featured', 'date' => '2026-08-12', 'summary' => 'A concise, configured summary.', 'topics' => ['Notes', 'Systems'], 'sources' => ['https://example.org/source']]), '/featured'),
            new ArticleDocument('latest', 'Latest essay', 'A second thought.', new FrontMatter(['title' => 'Latest essay', 'slug' => 'latest', 'date' => '2026-08-11', 'summary' => 'Another configured summary.', 'topics' => ['Notes']]), '/latest'),
        ];

        $manifest = (new StaticBuilder())->build(new BuildInput($articles, 'HolyMD Notes', 'https://example.test', 'Ada Author', 'A short author biography.'), $this->outputRoot);

        self::assertContains('assets/site.css', $manifest->files);
        self::assertFileExists($this->outputRoot . '/assets/site.css');

        $home = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertStringContainsString('<link rel="stylesheet" href="/assets/site.css">', $home);
        self::assertStringContainsString('<nav aria-label="Primary">', $home);
        self::assertStringContainsString('Featured writing', $home);
        self::assertStringContainsString('Latest writing', $home);
        self::assertStringContainsString('/topics/notes/', $home);
        self::assertStringContainsString('class="site-footer"', $home);

        $article = (string) file_get_contents($this->outputRoot . '/articles/featured/index.html');
        self::assertStringContainsString('class="reading-meta"', $article);
        self::assertStringContainsString('class="prose"', $article);
        self::assertStringContainsString('class="author-box"', $article);
        self::assertStringContainsString('id="related-heading"', $article);
        self::assertStringContainsString('Published', $article);
        self::assertStringContainsString('Sources', $article);

        $about = (string) file_get_contents($this->outputRoot . '/about/index.html');
        self::assertStringContainsString('A short author biography.', $about);
        self::assertStringContainsString('<nav aria-label="Primary">', $about);

        $topic = (string) file_get_contents($this->outputRoot . '/topics/notes/index.html');
        self::assertStringContainsString('Articles on Notes', $topic);
        self::assertStringContainsString('<nav aria-label="Primary">', $topic);
    }

    public function test_renders_configured_valid_site_language_on_every_page(): void
    {
        $article = new ArticleDocument('language', 'Language', 'Body.', new FrontMatter(['title' => 'Language', 'slug' => 'language', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/language');
        (new StaticBuilder())->build(new BuildInput([$article], 'Notes', 'https://example.test', 'Ada', 'About Ada', false, 'fr-CA'), $this->outputRoot);

        foreach (['/index.html', '/about/index.html', '/articles/language/index.html', '/topics/notes/index.html'] as $path) {
            self::assertStringContainsString('<html lang="fr-CA">', (string) file_get_contents($this->outputRoot . $path));
        }
    }

    public function test_rejects_an_invalid_site_language(): void
    {
        $article = new ArticleDocument('language', 'Language', 'Body.', new FrontMatter(['title' => 'Language', 'slug' => 'language', 'date' => '2026-08-12']), '/language');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('site language');
        (new StaticBuilder())->build(new BuildInput([$article], 'Notes', 'https://example.test', 'Ada', 'About Ada', false, 'invalid language'), $this->outputRoot);
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

    public function test_renders_table_of_contents_when_three_or_more_headings_exist(): void
    {
        $markdown = "# Section One\n\nContent one.\n\n## Subsection A\n\nContent A.\n\n# Section Two\n\nContent two.";
        $article = new ArticleDocument('toc-test', 'TOC Test', $markdown, new FrontMatter(['title' => 'TOC Test', 'slug' => 'toc-test', 'date' => '2026-08-12']), '/toc-test');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/toc-test/index.html');

        self::assertStringContainsString('class="toc-box"', $html);
        self::assertStringContainsString('href="#section-one"', $html);
        self::assertStringContainsString('href="#subsection-a"', $html);
        self::assertStringContainsString('href="#section-two"', $html);
    }

    public function test_llms_txt_includes_geo_metadata_when_present(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'GEO Test Article',
            'slug' => 'geo-test',
            'date' => '2026-08-12',
            'summary' => 'A GEO-reviewed summary for AI search.',
            'topics' => ['AI', 'GEO'],
            'entities' => 'Entity A, Entity B',
            'faq' => ['What is GEO?'],
            'metadata_suggestion' => 'Suggested metadata for title & description',
        ]);
        $article = new ArticleDocument('geo-test', 'GEO Test Article', 'Article body markdown.', $frontMatter, '/geo-test');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

        $llmsTxt = (string) file_get_contents($this->outputRoot . '/llms.txt');
        self::assertStringContainsString('- [GEO Test Article](https://example.test/articles/geo-test/): A GEO-reviewed summary for AI search.', $llmsTxt);

        $llmsFullTxt = (string) file_get_contents($this->outputRoot . '/llms-full.txt');
        self::assertStringContainsString('Summary: A GEO-reviewed summary for AI search.', $llmsFullTxt);
        self::assertStringContainsString('Topics: AI, GEO', $llmsFullTxt);
        self::assertStringContainsString('Entities: Entity A, Entity B', $llmsFullTxt);
        self::assertStringContainsString('FAQ: What is GEO?', $llmsFullTxt);
        self::assertStringContainsString('Metadata: Suggested metadata for title & description', $llmsFullTxt);
    }
}
