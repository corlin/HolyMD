<?php

declare(strict_types=1);

namespace HolyMD\Tests\Render;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Render\BuildInput;
use HolyMD\Render\MarkdownRendererInterface;
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
        self::assertFileExists($this->outputRoot . '/atom.xml');
        self::assertFileExists($this->outputRoot . '/404.html');
        self::assertFileExists($this->outputRoot . '/sitemap.xml');
        self::assertFileExists($this->outputRoot . '/llms.txt');
        self::assertFileExists($this->outputRoot . '/llms-full.txt');
        self::assertStringContainsString('Page not found', (string) file_get_contents($this->outputRoot . '/404.html'));
        self::assertStringContainsString('xmlns="http://www.w3.org/2005/Atom"', (string) file_get_contents($this->outputRoot . '/atom.xml'));
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

        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/search.js');
        $cssName = 'assets/site.' . substr(hash('sha256', $styles), 0, 10) . '.css';
        $scriptName = 'assets/search.' . substr(hash('sha256', $script), 0, 10) . '.js';
        self::assertContains($cssName, $manifest->files);
        self::assertContains($scriptName, $manifest->files);
        self::assertContains('search-index.json', $manifest->files);
        self::assertFileExists($this->outputRoot . '/' . $cssName);

        $home = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertStringContainsString('<link rel="stylesheet" href="/assets/site.' . substr(hash('sha256', $styles), 0, 10) . '.css">', $home);
        self::assertStringContainsString('id="site-search"', $home);
        self::assertStringContainsString('<nav aria-label="Primary">', $home);
        self::assertStringContainsString('Featured writing', $home);
        self::assertStringContainsString('Latest writing', $home);
        self::assertStringContainsString('/topics/notes/', $home);
        self::assertStringContainsString('class="site-footer"', $home);

        $article = (string) file_get_contents($this->outputRoot . '/articles/featured/index.html');
        self::assertStringContainsString('class="reading-layout shell"', $article);
        self::assertStringContainsString('class="reading-meta"', $article);
        self::assertStringContainsString('class="prose"', $article);
        self::assertStringContainsString('class="author-box"', $article);
        self::assertStringContainsString('role="progressbar" aria-label="Reading progress"', $article);
        self::assertStringContainsString('class="image-viewer" aria-labelledby="image-viewer-title"', $article);
        self::assertStringContainsString('data-image-zoom="in"', $article);
        self::assertStringContainsString('data-image-close', $article);
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

    public function test_symbol_topics_receive_distinct_stable_routes(): void
    {
        $one = new ArticleDocument('cpp', 'C++', 'Body', new FrontMatter(['title' => 'C++', 'slug' => 'cpp', 'date' => '2026-08-12', 'topics' => ['C++']]), '/cpp');
        $two = new ArticleDocument('csharp', 'C#', 'Body', new FrontMatter(['title' => 'C#', 'slug' => 'csharp', 'date' => '2026-08-11', 'topics' => ['C#']]), '/csharp');
        (new StaticBuilder())->build(new BuildInput([$one, $two], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $routes = glob($this->outputRoot . '/topics/c-*/index.html') ?: [];
        self::assertCount(2, $routes);
        $home = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertSame(2, substr_count($home, 'href="/topics/c-'));
    }

    public function test_chinese_topic_uses_one_consistent_public_route_across_discovery_pages(): void
    {
        $article = new ArticleDocument('network', 'Network', 'Body.', new FrontMatter(['title' => 'Network', 'slug' => 'network', 'date' => '2026-08-12', 'topics' => ['复杂网络']]), '/network');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $routes = glob($this->outputRoot . '/topics/topic-*/index.html') ?: [];
        self::assertCount(1, $routes);
        $slug = basename(dirname($routes[0]));
        self::assertMatchesRegularExpression('/^topic-[a-f0-9]{10}$/', $slug);
        foreach (['/index.html', '/articles/network/index.html', '/sitemap.xml'] as $path) {
            self::assertStringContainsString('/topics/' . $slug . '/', (string) file_get_contents($this->outputRoot . $path), $path);
        }
    }

    public function test_numeric_topic_uses_a_valid_consistent_route(): void
    {
        $article = new ArticleDocument('year', 'Year', 'Body.', new FrontMatter(['title' => 'Year', 'slug' => 'year', 'date' => '2026-08-12', 'topics' => ['2026']]), '/year');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        foreach (['/index.html', '/articles/year/index.html', '/topics/2026/index.html', '/sitemap.xml'] as $path) {
            self::assertFileExists($this->outputRoot . $path);
        }
        self::assertStringContainsString('/topics/2026/', (string) file_get_contents($this->outputRoot . '/articles/year/index.html'));
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

    public function test_table_of_contents_decodes_heading_entities_and_assigns_unique_ids(): void
    {
        $markdown = "# 300–800 & **Value**\n\nFirst.\n\n# 300 800 Value 2\n\nSecond.\n\n# 300–800 & *Value*\n\nThird.\n\n# 中文标题\n\nFourth.";
        $article = new ArticleDocument('toc-duplicates', 'TOC duplicates', $markdown, new FrontMatter(['title' => 'TOC duplicates', 'slug' => 'toc-duplicates', 'date' => '2026-08-12']), '/toc-duplicates');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/toc-duplicates/index.html');

        self::assertStringContainsString('<h2 id="300-800-value">300–800 &amp; <strong>Value</strong></h2>', $html);
        self::assertStringContainsString('<h2 id="300-800-value-2">300 800 Value 2</h2>', $html);
        self::assertStringContainsString('<h2 id="300-800-value-3">300–800 &amp; <em>Value</em></h2>', $html);
        self::assertStringContainsString('href="#300-800-value">300–800 &amp; Value</a>', $html);
        self::assertStringContainsString('href="#300-800-value-3">300–800 &amp; Value</a>', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertSame(1, substr_count($html, 'id="300-800-value"'));
    }

    public function test_public_pages_do_not_link_to_disabled_llms_outputs(): void
    {
        $article = new ArticleDocument('without-llms', 'Without LLMs', 'Body.', new FrontMatter(['title' => 'Without LLMs', 'slug' => 'without-llms', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/without-llms');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', false), $this->outputRoot);

        self::assertFileDoesNotExist($this->outputRoot . '/llms.txt');
        self::assertFileDoesNotExist($this->outputRoot . '/llms-full.txt');
        foreach (['/index.html', '/about/index.html', '/articles/without-llms/index.html', '/topics/notes/index.html'] as $path) {
            $html = (string) file_get_contents($this->outputRoot . $path);
            self::assertStringNotContainsString('href="/llms.txt"', $html, $path);
            self::assertStringNotContainsString('href="/llms-full.txt"', $html, $path);
        }
    }

    public function test_public_toc_starts_closed_and_theme_controls_expose_selection_state(): void
    {
        $article = new ArticleDocument('accessible-controls', 'Accessible controls', "# One\n\nFirst.\n\n# Two\n\nSecond.\n\n# Three\n\nThird.", new FrontMatter(['title' => 'Accessible controls', 'slug' => 'accessible-controls', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/accessible-controls');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $articleHtml = (string) file_get_contents($this->outputRoot . '/articles/accessible-controls/index.html');
        self::assertStringContainsString('class="toc-rail" aria-labelledby="desktop-toc-heading"', $articleHtml);
        self::assertStringContainsString('<details class="toc-box">', $articleHtml);
        self::assertStringNotContainsString('<details class="toc-box" open>', $articleHtml);

        foreach (['/index.html', '/about/index.html', '/articles/accessible-controls/index.html', '/topics/notes/index.html'] as $path) {
            $html = (string) file_get_contents($this->outputRoot . $path);
            self::assertStringContainsString('class="theme-switcher" role="group" aria-label="Theme switcher"', $html, $path);
            self::assertSame(1, substr_count($html, 'aria-pressed="true"'), $path);
            self::assertSame(2, substr_count($html, 'aria-pressed="false"'), $path);
        }

        $sourceStyles = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');
        $styles = (string) file_get_contents($this->outputRoot . '/assets/site.' . substr(hash('sha256', $sourceStyles), 0, 10) . '.css');
        self::assertStringContainsString('min-width: 2.75rem', $styles);
        self::assertStringContainsString('min-height: 2.75rem', $styles);
    }

    public function test_each_article_is_rendered_exactly_once_per_build(): void
    {
        $counter = new class implements MarkdownRendererInterface {
            public int $calls = 0;
            public function render(string $markdown): string
            {
                $this->calls++;
                return '<p>rendered</p>';
            }
        };
        $articles = [
            new ArticleDocument('one', 'One', 'Body one.', new FrontMatter(['title' => 'One', 'slug' => 'one', 'date' => '2026-08-12']), '/one'),
            new ArticleDocument('two', 'Two', 'Body two.', new FrontMatter(['title' => 'Two', 'slug' => 'two', 'date' => '2026-08-11']), '/two'),
        ];
        (new StaticBuilder(null, $counter))->build(new BuildInput($articles, 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        self::assertSame(2, $counter->calls);
        $feed = (string) file_get_contents($this->outputRoot . '/feed.json');
        self::assertSame(2, substr_count($feed, '"content_html": "<p>rendered</p>"'));
    }

    public function test_injected_build_timestamp_drives_feed_and_sitemap_freshness_signals(): void
    {
        $article = new ArticleDocument('fresh', 'Fresh', 'Body.', new FrontMatter(['title' => 'Fresh', 'slug' => 'fresh', 'date' => '2026-08-12', 'updated' => '2026-08-10']), '/fresh');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', false, 'zh-CN', '2026-08-13T04:00:00+00:00'), $this->outputRoot);

        $rss = (string) file_get_contents($this->outputRoot . '/rss.xml');
        self::assertStringContainsString('<lastBuildDate>', $rss);
        $sitemap = (string) file_get_contents($this->outputRoot . '/sitemap.xml');
        self::assertStringContainsString('<lastmod>2026-08-10</lastmod>', $sitemap);
        self::assertStringContainsString('<lastmod>2026-08-13</lastmod>', $sitemap);
        $feed = (string) file_get_contents($this->outputRoot . '/feed.json');
        self::assertStringContainsString('"date_modified": "2026-08-10"', $feed);
    }

    public function test_base_path_prefixes_internal_links_and_assets(): void
    {
        $article = new ArticleDocument('based', 'Based', 'Body.', new FrontMatter(['title' => 'Based', 'slug' => 'based', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/based');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test/holymd', 'Author', 'About', false, 'zh-CN', null, '/holymd'), $this->outputRoot);

        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');
        $cssUrl = '/holymd/assets/site.' . substr(hash('sha256', $styles), 0, 10) . '.css';
        $home = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertStringContainsString('<link rel="stylesheet" href="' . $cssUrl . '">', $home);
        self::assertStringContainsString('href="/holymd/about/"', $home);
        self::assertStringContainsString('href="/holymd/rss.xml"', $home);
        $topic = (string) file_get_contents($this->outputRoot . '/topics/notes/index.html');
        self::assertStringContainsString('href="/holymd/"', $topic);
    }

    public function test_search_index_exposes_plain_text_and_metadata_with_a_size_cap(): void
    {
        $article = new ArticleDocument('long', 'Long', str_repeat('word ', 5000), new FrontMatter(['title' => 'Long', 'slug' => 'long', 'date' => '2026-08-12', 'summary' => 'Sum', 'topics' => ['Notes']]), '/long');
        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $index = json_decode((string) file_get_contents($this->outputRoot . '/search-index.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('builtAt', $index);
        self::assertCount(1, $index['articles']);
        self::assertSame('long', $index['articles'][0]['slug']);
        self::assertSame(['Notes'], $index['articles'][0]['topics']);
        self::assertSame('Sum', $index['articles'][0]['summary']);
        self::assertStringContainsString('word', $index['articles'][0]['text']);
        self::assertLessThanOrEqual(12288, mb_strlen($index['articles'][0]['text']));
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
        self::assertStringNotContainsString('Metadata: Suggested metadata for title & description', $llmsFullTxt);
    }

    public function test_llms_full_serializes_nested_geo_metadata_without_php_array_coercion(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'Nested GEO metadata',
            'slug' => 'nested-geo',
            'date' => '2026-08-12',
            'entities' => [['name' => 'HolyMD', 'type' => 'SoftwareApplication']],
            'faq' => [['question' => 'Does AI rewrite prose?', 'answer' => 'No.']],
        ]);
        $article = new ArticleDocument('nested-geo', 'Nested GEO metadata', 'Body.', $frontMatter, '/nested-geo');

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);
        } finally {
            restore_error_handler();
        }

        $llmsFull = (string) file_get_contents($this->outputRoot . '/llms-full.txt');
        self::assertStringContainsString('Entities: [{"name":"HolyMD","type":"SoftwareApplication"}]', $llmsFull);
        self::assertStringContainsString('FAQ: [{"question":"Does AI rewrite prose?","answer":"No."}]', $llmsFull);
        self::assertStringNotContainsString('Array', $llmsFull);
    }

    public function test_geo_metadata_changes_the_public_article_semantics(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'Applied GEO metadata',
            'slug' => 'applied-geo',
            'date' => '2026-08-12',
            'entities' => ['HolyMD', 'GEO'],
            'faq' => [['question' => 'Does GEO rewrite the article?', 'answer' => 'No.']],
            'alt_text' => ['A diagram of the GEO review flow'],
            'internal_links' => ['/articles/next/', 'https://example.test/about/'],
        ]);
        $article = new ArticleDocument('applied-geo', 'Applied GEO metadata', "![](/media/flow.png)\n", $frontMatter, '/applied-geo');

        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

        $html = (string) file_get_contents($this->outputRoot . '/articles/applied-geo/index.html');
        self::assertStringContainsString('alt="A diagram of the GEO review flow"', $html);
        self::assertStringContainsString('Related links', $html);
        self::assertStringContainsString('href="/articles/next/"', $html);
        self::assertStringContainsString('"about":[{"@type":"Thing","name":"HolyMD"},{"@type":"Thing","name":"GEO"}]', $html);
        self::assertStringContainsString('"@type":"FAQPage"', $html);
        self::assertStringContainsString('Does GEO rewrite the article?', $html);
    }

    public function test_llms_titles_cannot_inject_markdown_entries_or_headings(): void
    {
        $title = "Safe]\n- [Injected](https://evil.test)";
        $article = new ArticleDocument('safe', $title, 'Body.', new FrontMatter(['title' => $title, 'slug' => 'safe', 'date' => '2026-08-12']), '/safe');

        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

        $llms = (string) file_get_contents($this->outputRoot . '/llms.txt');
        $full = (string) file_get_contents($this->outputRoot . '/llms-full.txt');
        self::assertStringNotContainsString("\n- [Injected]", $llms);
        self::assertStringNotContainsString("\n- [Injected]", $full);
        self::assertStringNotContainsString("\n# Injected", $full);
        self::assertStringContainsString('Safe\\] - \\[Injected\\]\\(https://evil.test\\)', $llms);
    }

    public function test_legacy_geo_suggestions_are_not_published_as_accepted_facts(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'Legacy review', 'slug' => 'legacy-review', 'date' => '2026-08-12',
            'metadata_suggestion' => 'Author: unknown',
            'sources_suggestion' => 'Maybe cite a paper.',
            'structured_data_suggestion' => 'Article author unknown',
        ]);
        $article = new ArticleDocument('legacy-review', 'Legacy review', 'Body.', $frontMatter, '/legacy-review');

        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

        $full = (string) file_get_contents($this->outputRoot . '/llms-full.txt');
        self::assertStringNotContainsString('Author: unknown', $full);
        self::assertStringNotContainsString('Maybe cite a paper.', $full);
        self::assertStringNotContainsString('Article author unknown', $full);
    }

    public function test_reading_time_uses_words_for_latin_text_and_characters_for_cjk_text(): void
    {
        $english = new ArticleDocument('english-time', 'English time', implode(' ', array_fill(0, 300, 'word')), new FrontMatter(['title' => 'English time', 'slug' => 'english-time', 'date' => '2026-08-12']), '/english-time');
        $chinese = new ArticleDocument('chinese-time', 'Chinese time', str_repeat('字', 300), new FrontMatter(['title' => 'Chinese time', 'slug' => 'chinese-time', 'date' => '2026-08-11']), '/chinese-time');
        (new StaticBuilder())->build(new BuildInput([$english, $chinese], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        self::assertStringContainsString('2 min read', (string) file_get_contents($this->outputRoot . '/articles/english-time/index.html'));
        self::assertStringContainsString('1 min read', (string) file_get_contents($this->outputRoot . '/articles/chinese-time/index.html'));
    }

    public function test_extracts_og_image_from_front_matter_or_body(): void
    {
        $coverArticle = new ArticleDocument('cover-post', 'Cover Post', 'Body without images.', new FrontMatter(['title' => 'Cover Post', 'slug' => 'cover-post', 'date' => '2026-08-12', 'cover_image' => '/media/hero.jpg']), '/cover-post');
        $bodyImageArticle = new ArticleDocument('body-post', 'Body Post', 'Some text and ![photo](/media/photo.png).', new FrontMatter(['title' => 'Body Post', 'slug' => 'body-post', 'date' => '2026-08-11']), '/body-post');
        $noImageArticle = new ArticleDocument('no-image', 'No Image', 'Just pure text.', new FrontMatter(['title' => 'No Image', 'slug' => 'no-image', 'date' => '2026-08-10']), '/no-image');

        (new StaticBuilder())->build(new BuildInput([$coverArticle, $bodyImageArticle, $noImageArticle], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $coverHtml = (string) file_get_contents($this->outputRoot . '/articles/cover-post/index.html');
        self::assertStringContainsString('<meta property="og:image" content="https://example.test/media/hero.jpg">', $coverHtml);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $coverHtml);
        self::assertStringContainsString('"image":"https://example.test/media/hero.jpg"', $coverHtml);

        $bodyHtml = (string) file_get_contents($this->outputRoot . '/articles/body-post/index.html');
        self::assertStringContainsString('<meta property="og:image" content="https://example.test/media/photo.png">', $bodyHtml);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $bodyHtml);
        self::assertStringContainsString('"image":"https://example.test/media/photo.png"', $bodyHtml);

        $noImgHtml = (string) file_get_contents($this->outputRoot . '/articles/no-image/index.html');
        self::assertStringNotContainsString('og:image', $noImgHtml);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $noImgHtml);
    }

    public function test_homepage_limits_archive_to_10_and_shows_load_more_when_needed(): void
    {
        $articles = [];
        for ($i = 1; $i <= 15; $i++) {
            $articles[] = new ArticleDocument("post-{$i}", "Post {$i}", "Body {$i}", new FrontMatter(['title' => "Post {$i}", 'slug' => "post-{$i}", 'date' => sprintf('2026-08-%02d', 30 - $i)]), "/post-{$i}");
        }

        (new StaticBuilder())->build(new BuildInput($articles, 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $home = (string) file_get_contents($this->outputRoot . '/index.html');
        // 1 featured + 10 in archive list = 11 post headings
        self::assertStringContainsString('id="load-more-button"', $home);
        self::assertStringContainsString('Post 1', $home); // featured
        self::assertStringContainsString('Post 11', $home); // 10th in archive
        self::assertStringNotContainsString('Post 12', $home); // 11th in archive (not SSR'd)
    }

    public function test_renders_custom_pages_and_nav_order_links(): void
    {
        $article = new ArticleDocument('hello', 'Hello World', 'First post', new FrontMatter(['title' => 'Hello World', 'slug' => 'hello', 'date' => '2026-08-14']), '/hello');
        $page1 = new ArticleDocument('privacy', 'Privacy Policy', '# Privacy Rules', new FrontMatter(['title' => 'Privacy Policy', 'slug' => 'privacy', 'date' => '2026-08-14', 'nav_order' => 2, 'status' => 'published']), '/privacy');
        $page2 = new ArticleDocument('terms', 'Terms', '# Terms Rules', new FrontMatter(['title' => 'Terms', 'slug' => 'terms', 'date' => '2026-08-14', 'nav_order' => 1, 'status' => 'published']), '/terms');
        $draftPage = new ArticleDocument('secret', 'Secret', '# Secret', new FrontMatter(['title' => 'Secret', 'slug' => 'secret', 'date' => '2026-08-14', 'status' => 'draft']), '/secret');

        (new StaticBuilder())->build(new BuildInput([$article], 'Site', 'https://example.test', 'Author', 'About', false, 'zh-CN', null, '', [$page1, $page2, $draftPage]), $this->outputRoot);

        self::assertFileExists($this->outputRoot . '/privacy/index.html');
        self::assertFileExists($this->outputRoot . '/terms/index.html');
        self::assertFileDoesNotExist($this->outputRoot . '/secret/index.html');

        $privacyHtml = (string) file_get_contents($this->outputRoot . '/privacy/index.html');
        self::assertStringContainsString('Privacy Rules', $privacyHtml);

        // Check sitemap includes published pages
        $sitemap = (string) file_get_contents($this->outputRoot . '/sitemap.xml');
        self::assertStringContainsString('https://example.test/privacy/', $sitemap);
        self::assertStringContainsString('https://example.test/terms/', $sitemap);
        self::assertStringNotContainsString('https://example.test/secret/', $sitemap);

        // Check header nav order: terms (nav_order=1) should precede privacy (nav_order=2)
        $homeHtml = (string) file_get_contents($this->outputRoot . '/index.html');
        $termsPos = strpos($homeHtml, 'href="/terms/"');
        $privacyPos = strpos($homeHtml, 'href="/privacy/"');
        self::assertNotFalse($termsPos);
        self::assertNotFalse($privacyPos);
        self::assertLessThan($privacyPos, $termsPos);
    }
}
