<?php

declare(strict_types=1);

namespace HolyMD\Tests\Render;

use HolyMD\Config\PublicationSettings;
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

        $manifest = (new StaticBuilder())->build($this->input($articles, 'HolyMD Notes', 'https://example.test', 'Ada Author', 'About Ada.', true), $this->outputRoot);

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
        self::assertStringContainsString('"@type":"BlogPosting"', $article);
        self::assertStringContainsString('"publisher":{"@type":"Organization","name":"HolyMD Notes"', $article);
        self::assertStringContainsString('"inLanguage":"zh-CN"', $article);
        self::assertStringContainsString('https://example.test/articles/first-post/', $article);
        $indexHtml = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertStringContainsString('"@type":"WebSite"', $indexHtml);
        self::assertStringContainsString('"@type":"Person"', $indexHtml);
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
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/blocks/index.html');
        self::assertStringContainsString("<ul>\n<li>item</li>\n</ul>\n<h2 id=\"heading\">Heading</h2>\n<blockquote>", $html);
        self::assertStringContainsString("</blockquote>\n<h3 id=\"subheading\">Subheading</h3>", $html);
        self::assertSame(1, substr_count($html, '<h1'));
    }

    public function test_closes_quote_before_list(): void
    {
        $article = new ArticleDocument('transitions', 'Transitions', "> quote\n- item", new FrontMatter(['title' => 'Transitions', 'slug' => 'transitions', 'date' => '2026-08-12']), '/transitions');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
        $html = (string) file_get_contents($this->outputRoot . '/articles/transitions/index.html');
        self::assertStringContainsString("</blockquote>\n<ul>", $html);
    }

    public function test_renders_visible_sources_and_propagates_them_to_json_ld(): void
    {
        $article = new ArticleDocument('sourced', 'Sourced', 'Evidence.', new FrontMatter(['title' => 'Sourced', 'slug' => 'sourced', 'date' => '2026-08-12', 'sources' => ['https://example.org/evidence']]), '/sourced');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
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

        $manifest = (new StaticBuilder())->build($this->input($articles, 'HolyMD Notes', 'https://example.test', 'Ada Author', 'A short author biography.'), $this->outputRoot);

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

        $topic = (string) file_get_contents($this->outputRoot . '/topics/notes/index.html');
        self::assertStringContainsString('Articles on Notes', $topic);
        self::assertStringContainsString('<nav aria-label="Primary">', $topic);
    }

    public function test_renders_configured_valid_site_language_on_every_page(): void
    {
        $article = new ArticleDocument('language', 'Language', 'Body.', new FrontMatter(['title' => 'Language', 'slug' => 'language', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/language');
        (new StaticBuilder())->build($this->input([$article], 'Notes', 'https://example.test', 'Ada', 'About Ada', false, 'fr-CA'), $this->outputRoot);

        foreach (['/index.html', '/articles/language/index.html', '/topics/notes/index.html'] as $path) {
            self::assertStringContainsString('<html lang="fr-CA">', (string) file_get_contents($this->outputRoot . $path));
        }
    }

    public function test_rejects_an_invalid_site_language(): void
    {
        $article = new ArticleDocument('language', 'Language', 'Body.', new FrontMatter(['title' => 'Language', 'slug' => 'language', 'date' => '2026-08-12']), '/language');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('site language');
        (new StaticBuilder())->build($this->input([$article], 'Notes', 'https://example.test', 'Ada', 'About Ada', false, 'invalid language'), $this->outputRoot);
    }

    public function test_rejects_duplicate_articles_and_topic_slug_collisions(): void
    {
        $one = new ArticleDocument('same', 'One', 'Body', new FrontMatter(['title' => 'One', 'slug' => 'same', 'date' => '2026-08-12']), '/one');
        $two = new ArticleDocument('same', 'Two', 'Body', new FrontMatter(['title' => 'Two', 'slug' => 'same', 'date' => '2026-08-11']), '/two');
        $this->expectException(\RuntimeException::class);
        (new StaticBuilder())->build($this->input([$one, $two], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
    }

    public function test_symbol_topics_receive_distinct_stable_routes(): void
    {
        $one = new ArticleDocument('cpp', 'C++', 'Body', new FrontMatter(['title' => 'C++', 'slug' => 'cpp', 'date' => '2026-08-12', 'topics' => ['C++']]), '/cpp');
        $two = new ArticleDocument('csharp', 'C#', 'Body', new FrontMatter(['title' => 'C#', 'slug' => 'csharp', 'date' => '2026-08-11', 'topics' => ['C#']]), '/csharp');
        (new StaticBuilder())->build($this->input([$one, $two], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $routes = glob($this->outputRoot . '/topics/c-*/index.html') ?: [];
        self::assertCount(2, $routes);
        $home = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertSame(2, substr_count($home, 'href="/topics/c-'));
    }

    public function test_chinese_topic_uses_one_consistent_public_route_across_discovery_pages(): void
    {
        $article = new ArticleDocument('network', 'Network', 'Body.', new FrontMatter(['title' => 'Network', 'slug' => 'network', 'date' => '2026-08-12', 'topics' => ['复杂网络']]), '/network');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

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
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        foreach (['/index.html', '/articles/year/index.html', '/topics/2026/index.html', '/sitemap.xml'] as $path) {
            self::assertFileExists($this->outputRoot . $path);
        }
        self::assertStringContainsString('/topics/2026/', (string) file_get_contents($this->outputRoot . '/articles/year/index.html'));
    }

    public function test_renders_table_of_contents_when_three_or_more_headings_exist(): void
    {
        $markdown = "# Section One\n\nContent one.\n\n## Subsection A\n\nContent A.\n\n# Section Two\n\nContent two.";
        $article = new ArticleDocument('toc-test', 'TOC Test', $markdown, new FrontMatter(['title' => 'TOC Test', 'slug' => 'toc-test', 'date' => '2026-08-12']), '/toc-test');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
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
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);
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
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', false), $this->outputRoot);

        self::assertFileDoesNotExist($this->outputRoot . '/llms.txt');
        self::assertFileDoesNotExist($this->outputRoot . '/llms-full.txt');
        foreach (['/index.html', '/articles/without-llms/index.html', '/topics/notes/index.html'] as $path) {
            $html = (string) file_get_contents($this->outputRoot . $path);
            self::assertStringNotContainsString('href="/llms.txt"', $html, $path);
            self::assertStringNotContainsString('href="/llms-full.txt"', $html, $path);
        }
    }

    public function test_public_toc_starts_closed(): void
    {
        $article = new ArticleDocument('accessible-controls', 'Accessible controls', "# One\n\nFirst.\n\n# Two\n\nSecond.\n\n# Three\n\nThird.", new FrontMatter(['title' => 'Accessible controls', 'slug' => 'accessible-controls', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/accessible-controls');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $articleHtml = (string) file_get_contents($this->outputRoot . '/articles/accessible-controls/index.html');
        self::assertStringContainsString('class="toc-rail" aria-labelledby="desktop-toc-heading"', $articleHtml);
        self::assertStringContainsString('<details class="toc-box">', $articleHtml);
        self::assertStringNotContainsString('<details class="toc-box" open>', $articleHtml);
    }

    public function test_public_pages_expose_display_mode_controls(): void
    {
        $article = new ArticleDocument('accessible-controls', 'Accessible controls', 'Body.', new FrontMatter(['title' => 'Accessible controls', 'slug' => 'accessible-controls', 'date' => '2026-08-12', 'topics' => ['Notes']]), '/accessible-controls');
        $about = new ArticleDocument('about', 'About', 'About the author.', new FrontMatter(['title' => 'About', 'slug' => 'about', 'date' => '2026-08-12', 'nav_order' => 1]), '/about');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', false, 'zh-CN', null, '', [$about]), $this->outputRoot);

        foreach (['/index.html', '/articles/accessible-controls/index.html', '/topics/notes/index.html', '/about/index.html'] as $path) {
            $html = (string) file_get_contents($this->outputRoot . $path);
            self::assertStringContainsString('class="theme-switcher" role="group" aria-label="Display mode"', $html, $path);
            self::assertStringContainsString('data-theme-cycle aria-label="Display mode: System. Activate to use Light mode." title="Change display mode"', $html, $path);
            foreach (['Theme switcher', 'Change theme', '"Theme:', ' theme.'] as $readerFacingThemeCopy) {
                self::assertStringNotContainsString($readerFacingThemeCopy, $html, $path);
            }
            self::assertSame(1, substr_count($html, 'aria-pressed="true"'), $path);
            self::assertSame(2, substr_count($html, 'aria-pressed="false"'), $path);
        }
    }

    public function test_display_mode_runtime_preserves_cycle_labels_state_and_compatibility_identifiers(): void
    {
        $article = new ArticleDocument('display-mode', 'Display mode', 'Body.', new FrontMatter(['title' => 'Display mode', 'slug' => 'display-mode', 'date' => '2026-08-12']), '/display-mode');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $html = (string) file_get_contents($this->outputRoot . '/index.html');
        self::assertStringContainsString('auto:{label:"System",next:"light",icon:"brightness_auto"},light:{label:"Light",next:"dark",icon:"light_mode"},dark:{label:"Dark",next:"auto",icon:"dark_mode"}', $html);
        self::assertStringContainsString('a.setAttribute("aria-label","Display mode: "+l.label+". Activate to use "+n.label+" mode.")', $html);
        self::assertStringContainsString('document.querySelectorAll("[data-theme-set]")', $html);
        self::assertStringContainsString('e.setAttribute("aria-pressed",a?"true":"false")', $html);
        self::assertStringContainsString('r&&a(e[t()].next)', $html);
        foreach (['auto', 'light', 'dark'] as $mode) {
            self::assertStringContainsString('data-theme-set="' . $mode . '"', $html);
        }
        self::assertSame(2, substr_count($html, 'localStorage.getItem("holymd_theme")'), 'pre-paint and interactive scripts must both read the compatibility storage key');
        self::assertStringContainsString('localStorage.setItem("holymd_theme",t)', $html);
        self::assertStringContainsString('localStorage.removeItem("holymd_theme")', $html);
        self::assertStringContainsString('document.documentElement.setAttribute("data-theme",t)', $html);
        self::assertStringContainsString('document.documentElement.removeAttribute("data-theme")', $html);
    }

    public function test_public_styles_define_scoped_semantic_tokens_consumed_by_components(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');
        $scopes = $this->cssTokenScopes($styles);
        $colorTokens = ['surface-canvas', 'surface-raised', 'text-primary', 'text-muted', 'action-primary', 'border-subtle', 'focus-ring'];
        $sortedColorTokens = $colorTokens;
        sort($sortedColorTokens);

        foreach (array_merge($colorTokens, ['font-display', 'font-reading', 'font-ui', 'font-code', 'page-width', 'reading-width', 'radius-subtle', 'radius-card', 'radius-pill']) as $token) {
            self::assertArrayHasKey($token, $scopes['light'], $token);
        }
        self::assertSame($sortedColorTokens, array_keys($scopes['dark']));
        self::assertSame($scopes['dark'], $scopes['systemDark']);
        self::assertStringContainsString('html { background: var(--surface-canvas); color: var(--text-primary); font-family: var(--font-ui);', $styles);
        self::assertStringContainsString('.prose { max-width: 46rem; padding-top: clamp(2rem, 4vw, 3rem); font-family: var(--font-reading);', $styles);
        self::assertStringContainsString(':focus-visible { outline: 3px solid var(--focus-ring);', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }

    public function test_light_focus_token_meets_contrast_against_public_surfaces(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');
        $lightTokens = $this->cssTokenScopes($styles)['light'];
        $canvasRatio = $this->contrastRatio($lightTokens['focus-ring'], $lightTokens['surface-canvas']);
        $raisedRatio = $this->contrastRatio($lightTokens['focus-ring'], $lightTokens['surface-raised']);

        self::assertGreaterThanOrEqual(3.0, $canvasRatio, sprintf('focus ring contrast against canvas is %.2f:1', $canvasRatio));
        self::assertGreaterThanOrEqual(3.0, $raisedRatio, sprintf('focus ring contrast against raised surface is %.2f:1', $raisedRatio));
    }

    public function test_search_field_exposes_a_visible_focus_within_treatment(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');
        self::assertStringContainsString('.header-search-dropdown .search-field:focus-within { border-color: var(--focus-ring); outline: 3px solid var(--focus-ring); outline-offset: 2px; }', $styles);
    }

    public function test_generated_public_styles_keep_mobile_header_long_content_and_footer_within_the_viewport(): void
    {
        $article = new ArticleDocument(
            'responsive-reading',
            'AnExtremelyLongMixedLanguageTitleThatMustNotExpandThePage长标题',
            "## Long reading\n\n`AnUnbrokenInlineCodeTokenThatMustWrapLocally`\n\n| Column | Value |\n| --- | --- |\n| Long | AnUnbrokenTableValueThatMustScrollLocally |",
            new FrontMatter([
                'title' => 'AnExtremelyLongMixedLanguageTitleThatMustNotExpandThePage长标题',
                'slug' => 'responsive-reading',
                'date' => '2026-08-12',
                'summary' => 'AnExtremelyLongMixedLanguageDeckThatMustWrap这是一段很长的中文摘要',
            ]),
            '/responsive-reading',
        );
        (new StaticBuilder())->build($this->input([$article], 'HolyMD', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $styles = $this->generatedPublicStyles();
        self::assertStringContainsString('--control-size: 2.75rem;', $styles);
        self::assertStringContainsString('.wordmark { display: inline-flex; align-items: center; flex: none; min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringContainsString('.brand-nav nav a { display: inline-flex; align-items: center; min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringContainsString('.header-search-trigger { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringContainsString('.search-close-btn { min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringContainsString('.toc-box summary { display: flex; align-items: center; min-height: var(--control-size);', $styles);
        self::assertStringContainsString('.toc-box a, .toc-rail a { display: flex; align-items: center; min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringContainsString('.article-header h1, .article-header .deck, .page-intro h1, .page-intro .deck, .feature-card h3, .feature-summary, .article-row, .prose, .toc-box, .toc-rail, .author-box, .site-footer { overflow-wrap: anywhere; }', $styles);
        self::assertStringContainsString('.prose pre { max-width: 100%; overflow-x: auto;', $styles);
        self::assertStringContainsString('.prose table { display: block; width: 100%; max-width: 100%; overflow-x: auto;', $styles);
        self::assertStringContainsString('.site-footer > div { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1.25rem 2rem; align-items: flex-start; }', $styles);
        self::assertStringContainsString('.site-footer nav a { display: inline-flex; align-items: center; justify-content: center; min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringContainsString('@media (max-width: 48rem) {', $styles);
        self::assertStringContainsString('.brand-nav nav a:not(:first-child) { display: none; }', $styles);
        self::assertStringContainsString('.wordmark { max-width: min(12rem, 34vw); overflow: hidden; text-overflow: ellipsis; }', $styles);
        self::assertStringContainsString('@media (max-width: 38rem) {', $styles);
        self::assertStringContainsString('.nav-row { gap: .35rem; }', $styles);
        self::assertStringContainsString('.brand-nav { flex: 1 1 auto; gap: .35rem; min-width: 0; }', $styles);
        self::assertStringContainsString('.header-tools { flex: none; gap: .35rem; min-width: 0; }', $styles);
        self::assertStringContainsString('.wordmark { max-width: min(8rem, 34vw); overflow: hidden; text-overflow: ellipsis; }', $styles);
        self::assertStringContainsString('.image-viewer-actions button, .image-viewer-download, .image-viewer-nav { display: inline-flex; align-items: center; justify-content: center; min-width: var(--control-size); min-height: var(--control-size);', $styles);
        self::assertStringNotContainsString('.image-viewer-actions button, .image-viewer-download { min-width: var(--control-size); min-height: var(--control-size); }', $styles);
        foreach (['radius-control', 'radius-item', 'radius-overlay', 'radius-media'] as $token) {
            self::assertArrayHasKey($token, $this->cssTokenScopes($styles)['light']);
        }
        self::assertStringContainsString('border-radius: var(--radius-overlay);', $styles);
        self::assertStringContainsString('border-radius: var(--radius-control);', $styles);
        self::assertStringContainsString('border-radius: var(--radius-item);', $styles);
        self::assertStringContainsString('border-radius: var(--radius-media);', $styles);
    }

    public function test_generated_long_brand_has_no_computed_overflow_across_compact_breakpoint(): void
    {
        $this->buildResponsiveBrowserArticle();

        foreach ([375, 608, 609, 768, 769] as $viewportWidth) {
            $metrics = $this->browserMetrics($viewportWidth, <<<'JS'
<script>document.fonts.ready.then(function(){var offenders=[],wordmark=document.querySelector(".wordmark"),wordmarkStyle=getComputedStyle(wordmark);document.querySelectorAll("body *").forEach(function(element){var style=getComputedStyle(element),rect=element.getBoundingClientRect();if(style.display!=="none"&&(rect.right>innerWidth+.5||rect.left<-.5||element.scrollWidth>element.clientWidth+.5))offenders.push({tag:element.tagName,className:element.className,width:rect.width,left:rect.left,right:rect.right,clientWidth:element.clientWidth,scrollWidth:element.scrollWidth,overflowX:style.overflowX})});var o=document.createElement("output");o.id="browser-result";o.textContent=JSON.stringify({innerWidth:innerWidth,clientWidth:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth,bodyScrollWidth:document.body.scrollWidth,compact:matchMedia("(max-width: 38rem)").matches,fontLoaded:document.fonts.check('24px "Material Symbols Outlined"','search'),wordmark:{clientWidth:wordmark.clientWidth,scrollWidth:wordmark.scrollWidth,overflowX:wordmarkStyle.overflowX,textOverflow:wordmarkStyle.textOverflow,whiteSpace:wordmarkStyle.whiteSpace},offenders:offenders.slice(0,12)});document.body.appendChild(o)});</script>
JS);
            self::assertSame($viewportWidth, $metrics['innerWidth'], 'browser fixture viewport');
            self::assertTrue($metrics['fontLoaded'], $viewportWidth . 'px fixture must use the self-hosted Material Symbols font');
            self::assertGreaterThan($metrics['wordmark']['clientWidth'], $metrics['wordmark']['scrollWidth'], $viewportWidth . 'px intentionally long brand must be internally truncated');
            self::assertSame('hidden', $metrics['wordmark']['overflowX']);
            self::assertSame('ellipsis', $metrics['wordmark']['textOverflow']);
            self::assertSame('nowrap', $metrics['wordmark']['whiteSpace']);
            self::assertLessThanOrEqual($metrics['innerWidth'], $metrics['scrollWidth'], $viewportWidth . 'px generated article must not overflow: ' . json_encode($metrics));
            self::assertLessThanOrEqual($metrics['innerWidth'], $metrics['bodyScrollWidth'], $viewportWidth . 'px generated body must not overflow: ' . json_encode($metrics));
        }
    }

    public function test_generated_browser_fixture_loads_the_self_hosted_icon_font(): void
    {
        $this->buildConfiguredBrandBrowserArticle();

        $metrics = $this->browserMetrics(375, <<<'JS'
<script>document.fonts.ready.then(function(){var icon=document.querySelector(".header-search-trigger .icon"),o=document.createElement("output");o.id="browser-result";o.textContent=JSON.stringify({fontLoaded:document.fonts.check('24px "Material Symbols Outlined"','search'),fontFamily:getComputedStyle(icon).fontFamily,iconText:icon.textContent});document.body.appendChild(o)});</script>
JS);

        self::assertSame('search', $metrics['iconText']);
        self::assertStringContainsString('Material Symbols Outlined', $metrics['fontFamily']);
        self::assertTrue($metrics['fontLoaded'], 'generated browser fixture must load the public self-hosted Material Symbols font');
    }

    public function test_actual_configured_brand_and_mobile_header_controls_do_not_clip_or_overlap_at_375px(): void
    {
        $this->buildConfiguredBrandBrowserArticle();

        $metrics = $this->browserMetrics(375, <<<'JS'
<script>document.fonts.ready.then(function(){var wordmark=document.querySelector(".wordmark"),targets=[{name:"brand",element:wordmark},{name:"writing",element:document.querySelector('.brand-nav nav a')},{name:"search",element:document.querySelector('.header-search-trigger')},{name:"display",element:document.querySelector('.theme-cycle')}].filter(function(target){var style=getComputedStyle(target.element),rect=target.element.getBoundingClientRect();return style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0&&rect.height>0}).map(function(target){var rect=target.element.getBoundingClientRect();return {name:target.name,left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom,width:rect.width,height:rect.height}}),overlaps=[];for(var i=0;i<targets.length;i++)for(var j=i+1;j<targets.length;j++){var a=targets[i],b=targets[j],intersects=a.left<b.right-.5&&a.right>b.left+.5&&a.top<b.bottom-.5&&a.bottom>b.top+.5;if(intersects)overlaps.push(a.name+"/"+b.name)}var o=document.createElement("output");o.id="browser-result";o.textContent=JSON.stringify({fontLoaded:document.fonts.check('24px "Material Symbols Outlined"','search'),wordmarkText:wordmark.textContent,wordmarkClientWidth:wordmark.clientWidth,wordmarkScrollWidth:wordmark.scrollWidth,targets:targets,overlaps:overlaps,innerWidth:innerWidth,documentScrollWidth:document.documentElement.scrollWidth,bodyScrollWidth:document.body.scrollWidth});document.body.appendChild(o)});</script>
JS);

        self::assertSame("Corlin's Blog", $metrics['wordmarkText']);
        self::assertLessThanOrEqual($metrics['wordmarkClientWidth'], $metrics['wordmarkScrollWidth'], 'the actual configured wordmark must not clip its own text: ' . json_encode($metrics));
        self::assertSame(['brand', 'writing', 'search', 'display'], array_column($metrics['targets'], 'name'));
        self::assertSame([], $metrics['overlaps'], 'visible mobile header targets must not overlap: ' . json_encode($metrics));
        self::assertTrue($metrics['fontLoaded'], 'mobile header geometry must be measured with the production icon font loaded');
        self::assertLessThanOrEqual($metrics['innerWidth'], $metrics['documentScrollWidth'], 'the generated page must not overflow: ' . json_encode($metrics));
        self::assertLessThanOrEqual($metrics['innerWidth'], $metrics['bodyScrollWidth'], 'the generated body must not overflow: ' . json_encode($metrics));
    }

    public function test_all_generated_public_route_types_and_dynamic_search_classify_visible_interactives(): void
    {
        $this->buildResponsiveBrowserArticle();

        $routes = [
            '/index.html',
            '/articles/responsive-browser/index.html',
            '/topics/responsive-design/index.html',
            '/about/index.html',
            '/404.html',
        ];
        foreach ($routes as $route) {
            self::assertFileExists($this->outputRoot . $route, 'route matrix fixture');
            foreach ([375, 769, 1440] as $viewportWidth) {
                $metrics = $this->browserMetrics($viewportWidth, <<<'JS'
<script>
  var details=document.querySelector(".toc-box");if(details)details.open=true;
  var dropdown=document.querySelector(".header-search-dropdown");if(dropdown)dropdown.hidden=false;
  var dialog=document.querySelector(".image-viewer");if(dialog)dialog.setAttribute("open","");
  var disabledProbe=document.createElement("button");disabledProbe.disabled=true;disabledProbe.textContent="Disabled fixture control";document.body.appendChild(disabledProbe);
  document.querySelectorAll("#article-content img").forEach(function(image){image.style.width="120px";image.style.height="90px"});
  function exceptionFor(element){if(element.matches(".site-footer p a"))return "inline-footer-sentence";if(element.matches(".prose p a,.prose li a"))return "inline-prose";return null}
  function scan(){var interactives=[];document.querySelectorAll("a[href],button,summary,input:not([type=hidden]),select,textarea,[role=button],[tabindex]:not([tabindex='-1'])").forEach(function(element){var style=getComputedStyle(element),rect=element.getBoundingClientRect(),offscreen=rect.bottom<=0||rect.right<=0;if(element.matches(":disabled")||style.display==="none"||style.visibility==="hidden"||rect.width<=0||rect.height<=0||offscreen)return;var exception=exceptionFor(element);interactives.push({tag:element.tagName,className:element.className,text:(element.textContent||element.getAttribute("aria-label")||element.getAttribute("placeholder")||"").trim(),disabled:false,classification:exception?"inline-exception":"standalone",reason:exception,width:rect.width,height:rect.height})});var o=document.createElement("output");o.id="browser-result";o.textContent=JSON.stringify({interactives:interactives,searchResultCount:document.querySelectorAll(".header-search-item h4 a").length,requestedSearchIndex:window.__requestedSearchIndex||null,loadMoreCount:document.querySelectorAll("#load-more-button").length,desktopTocCount:Array.from(document.querySelectorAll(".toc-rail a")).filter(function(link){return getComputedStyle(link).display!=="none"&&getComputedStyle(link.parentElement).display!=="none"}).length});document.body.appendChild(o)}
  var input=document.getElementById("site-search");if(input){input.value="responsive";input.dispatchEvent(new Event("input",{bubbles:true}));setTimeout(scan,0)}else{scan()}
</script>
JS, '', $route, true);
                self::assertNotEmpty($metrics['interactives'], $route . ' at ' . $viewportWidth . 'px must expose interactive targets');
                self::assertGreaterThan(0, $metrics['searchResultCount'], $route . ' must scan results created by production search.js');
                self::assertSame('/search-index.json', $metrics['requestedSearchIndex'], $route . ' must exercise the production search-index URL');
                self::assertNotContains(true, array_column($metrics['interactives'], 'disabled'), 'disabled controls are not touch targets');
                if ($route === '/index.html') {
                    self::assertGreaterThan(0, $metrics['loadMoreCount'], 'home route matrix must include the conditional Load more state');
                }
                if ($route === '/articles/responsive-browser/index.html' && $viewportWidth === 1440) {
                    self::assertGreaterThan(0, $metrics['desktopTocCount'], 'desktop route state must include the visible TOC rail');
                }
                foreach ($metrics['interactives'] as $target) {
                    if ($target['classification'] === 'inline-exception') {
                        self::assertContains($target['reason'], ['inline-footer-sentence', 'inline-prose'], 'inline target classification must be auditable');
                        continue;
                    }
                    self::assertSame('standalone', $target['classification']);
                    self::assertGreaterThanOrEqual(44.0, $target['width'], sprintf('%s %dpx %s.%s %s width', $route, $viewportWidth, $target['tag'], $target['className'], $target['text']));
                    self::assertGreaterThanOrEqual(44.0, $target['height'], sprintf('%s %dpx %s.%s %s height', $route, $viewportWidth, $target['tag'], $target['className'], $target['text']));
                }
            }
        }
    }

    public function test_generated_article_keeps_h4_in_mobile_toc_but_suppresses_it_in_the_desktop_rail(): void
    {
        $markdown = "# Overview\n\nOne.\n\n## Detail\n\nTwo.\n\n### Fine point\n\nThree.";
        $article = new ArticleDocument('toc-depth', 'TOC depth', $markdown, new FrontMatter(['title' => 'TOC depth', 'slug' => 'toc-depth', 'date' => '2026-08-12']), '/toc-depth');
        (new StaticBuilder())->build($this->input([$article], 'HolyMD', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        $html = (string) file_get_contents($this->outputRoot . '/articles/toc-depth/index.html');
        self::assertSame(2, substr_count($html, '<li class="toc-level-4"><a href="#fine-point">Fine point</a></li>'), 'desktop and mobile TOCs must both retain H4 markup');

        $styles = $this->generatedPublicStyles();
        self::assertStringContainsString('.toc-rail-inner { position: sticky; top: 7rem; max-height: calc(100vh - 9rem); overflow: auto; padding: .15rem 1rem .5rem 0; scrollbar-width: thin; scrollbar-color: color-mix(in srgb, var(--text-muted) 35%, transparent) transparent; }', $styles);
        self::assertStringContainsString('.toc-rail-inner::-webkit-scrollbar { width: 4px; }', $styles);
        self::assertStringContainsString('.toc-rail-inner::-webkit-scrollbar-track { background: transparent; }', $styles);
        self::assertStringContainsString('.toc-rail-inner::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--text-muted) 35%, transparent); border-radius: var(--radius-pill); }', $styles);
        self::assertStringContainsString('@media (min-width: 64rem)', $styles);
        self::assertStringContainsString('.toc-rail .toc-level-4 { display: none; }', $styles);
        self::assertStringNotContainsString('.toc-box .toc-level-4 { display: none; }', $styles);

        self::assertStringContainsString('t.querySelectorAll("h2[id],h3[id]")', $html);
        self::assertStringNotContainsString('t.querySelectorAll("h2[id],h3[id],h4[id]")', $html);
    }

    public function test_css_token_parser_is_order_independent_and_rejects_duplicate_names(): void
    {
        $scopes = $this->cssTokenScopes('@media (prefers-color-scheme: dark) { html:not([data-theme="light"]) { --ink: #333333; } } :root { --ink: #111111; } html[data-theme="dark"] { --ink: #222222; }');
        self::assertSame(['ink' => '#111111'], $scopes['light']);
        self::assertSame(['ink' => '#222222'], $scopes['dark']);
        self::assertSame(['ink' => '#333333'], $scopes['systemDark']);

        $failure = null;
        try {
            $this->cssCustomProperties('--ink: #111111; --ink: #222222;');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            $failure = $caught;
        }
        self::assertNotNull($failure, 'duplicate semantic token declarations must fail instead of silently overwriting');
    }

    public function test_desktop_toc_runtime_never_tracks_the_hidden_h4_item(): void
    {
        $this->buildResponsiveBrowserArticle();
        $metrics = $this->browserMetrics(1440, <<<'JS'
<script>var current=document.querySelector('.toc-rail a[aria-current="location"]'),hidden=document.querySelector('.toc-rail .toc-level-4 a'),o=document.createElement("output");o.id="browser-result";o.textContent=JSON.stringify({observed:window.__tocObserved||[],current:current?current.getAttribute("href"):null,h4Display:hidden?getComputedStyle(hidden.parentElement).display:null});document.body.appendChild(o);</script>
JS, <<<'JS'
<script>window.__tocObserved=[];window.IntersectionObserver=class{constructor(callback){this.callback=callback}observe(element){window.__tocObserved.push(element.id)}}</script>
JS);

        self::assertSame(['overview', 'detail'], $metrics['observed']);
        self::assertSame('#overview', $metrics['current']);
        self::assertSame('none', $metrics['h4Display']);
    }

    public function test_browser_fixture_prefers_explicit_chrome_and_preserves_its_diagnostics(): void
    {
        $this->buildResponsiveBrowserArticle();
        $fakeChrome = $this->outputRoot . '/configured-chrome';
        file_put_contents($fakeChrome, "#!/bin/sh\necho 'configured Chrome diagnostic' >&2\nexit 42\n");
        chmod($fakeChrome, 0755);
        $previous = getenv('HOLYMD_CHROME_BIN');
        putenv('HOLYMD_CHROME_BIN=' . $fakeChrome);

        $failure = null;
        try {
            $this->browserMetrics(375, '<script><\/script>');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            $failure = $caught;
        } finally {
            $previous === false ? putenv('HOLYMD_CHROME_BIN') : putenv('HOLYMD_CHROME_BIN=' . $previous);
        }

        self::assertNotNull($failure, 'the explicitly configured browser must be used');
        self::assertStringContainsString('configured Chrome diagnostic', $failure->getMessage());
        self::assertStringContainsString($fakeChrome, $failure->getMessage());
    }

    public function test_browser_fixture_rejects_node_without_required_web_platform_support(): void
    {
        $this->buildResponsiveBrowserArticle();
        $fakeNode = $this->outputRoot . '/old-node';
        file_put_contents($fakeNode, "#!/bin/sh\necho 'v18.20.0'\n");
        chmod($fakeNode, 0755);
        $previous = getenv('HOLYMD_NODE_BIN');
        putenv('HOLYMD_NODE_BIN=' . $fakeNode);

        $failure = null;
        try {
            $this->browserMetrics(375, '<script><\/script>');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            $failure = $caught;
        } finally {
            $previous === false ? putenv('HOLYMD_NODE_BIN') : putenv('HOLYMD_NODE_BIN=' . $previous);
        }

        self::assertNotNull($failure, 'an unsupported Node runtime must fail before browser execution');
        self::assertStringContainsString('Node.js 22 or newer', $failure->getMessage());
    }

    public function test_browser_fixture_resolves_chromium_from_path(): void
    {
        $this->buildResponsiveBrowserArticle();
        $fakeChrome = $this->outputRoot . '/chromium';
        file_put_contents($fakeChrome, "#!/bin/sh\necho 'PATH Chromium diagnostic' >&2\nexit 43\n");
        chmod($fakeChrome, 0755);
        $previousPath = (string) getenv('PATH');
        $previousChrome = getenv('HOLYMD_CHROME_BIN');
        putenv('HOLYMD_CHROME_BIN');
        putenv('PATH=' . $this->outputRoot . PATH_SEPARATOR . $previousPath);

        $failure = null;
        try {
            $this->browserMetrics(375, '<script><\/script>');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            $failure = $caught;
        } finally {
            putenv('PATH=' . $previousPath);
            $previousChrome === false ? putenv('HOLYMD_CHROME_BIN') : putenv('HOLYMD_CHROME_BIN=' . $previousChrome);
        }

        self::assertNotNull($failure, 'Chromium on PATH must be selected before platform-specific fallback paths');
        self::assertStringContainsString('PATH Chromium diagnostic', $failure->getMessage());
        self::assertStringContainsString($fakeChrome, $failure->getMessage());
    }

    public function test_browser_fixture_bounds_chrome_startup_and_shutdown(): void
    {
        $this->buildResponsiveBrowserArticle();
        $fakeChrome = $this->outputRoot . '/sleeping-chrome';
        file_put_contents($fakeChrome, "#!/bin/sh\nexec sleep 30\n");
        chmod($fakeChrome, 0755);
        $previousChrome = getenv('HOLYMD_CHROME_BIN');
        $previousTimeout = getenv('HOLYMD_BROWSER_TIMEOUT_MS');
        putenv('HOLYMD_CHROME_BIN=' . $fakeChrome);
        putenv('HOLYMD_BROWSER_TIMEOUT_MS=150');
        $startedAt = microtime(true);

        $failure = null;
        try {
            $this->browserMetrics(375, '<script><\/script>');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            $failure = $caught;
        } finally {
            $previousChrome === false ? putenv('HOLYMD_CHROME_BIN') : putenv('HOLYMD_CHROME_BIN=' . $previousChrome);
            $previousTimeout === false ? putenv('HOLYMD_BROWSER_TIMEOUT_MS') : putenv('HOLYMD_BROWSER_TIMEOUT_MS=' . $previousTimeout);
        }

        self::assertNotNull($failure);
        self::assertLessThan(5.0, microtime(true) - $startedAt, 'startup and teardown must remain bounded');
        self::assertStringContainsString('Chrome startup timed out', $failure->getMessage());
    }

    public function test_chrome_metrics_runner_has_bounded_phases_and_sandbox_opt_in(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__) . '/Browser/chrome-metrics.mjs');
        foreach (['Chrome startup', 'DevTools endpoint readiness', 'DevTools target request', 'WebSocket connection', 'page load', 'Runtime.evaluate', 'Browser.close'] as $phase) {
            self::assertStringContainsString($phase, $runner);
        }
        self::assertStringContainsString('/json/list', $runner, 'runner must discover the existing blank target without mutating startup through /json/new');
        self::assertStringContainsString('HOLYMD_BROWSER_TIMEOUT_MS ?? 15000', $runner, 'default phase budget must tolerate the complete generated-route matrix');
        self::assertStringContainsString('Number.isSafeInteger(phaseTimeout)', $runner, 'Node and PHP must both require integer millisecond budgets');
        self::assertStringContainsString('phaseTimeout > 2147483647', $runner, 'Node and PHP must share a timer-safe maximum phase budget');
        self::assertStringContainsString("process.env.HOLYMD_CHROME_NO_SANDBOX === '1'", $runner);
        self::assertStringNotContainsString("'--headless=new', '--no-sandbox'", $runner);
    }

    public function test_browser_fixture_process_budget_tracks_the_configured_phase_timeout(): void
    {
        self::assertTrue(
            method_exists($this, 'browserProcessTimeoutMilliseconds'),
            'the PHP wrapper must derive its process budget from the configurable browser phase timeout',
        );
        if (!method_exists($this, 'browserProcessTimeoutMilliseconds')) {
            return;
        }

        $previous = getenv('HOLYMD_BROWSER_TIMEOUT_MS');
        putenv('HOLYMD_BROWSER_TIMEOUT_MS=60000');
        try {
            self::assertSame(605000, $this->browserProcessTimeoutMilliseconds());
            putenv('HOLYMD_BROWSER_TIMEOUT_MS=60000.5');
            self::assertSame(155000, $this->browserProcessTimeoutMilliseconds(), 'fractional budgets must fall back consistently');
            putenv('HOLYMD_BROWSER_TIMEOUT_MS=2147483648');
            self::assertSame(155000, $this->browserProcessTimeoutMilliseconds(), 'budgets above the shared timer-safe maximum must fall back consistently');
        } finally {
            $previous === false ? putenv('HOLYMD_BROWSER_TIMEOUT_MS') : putenv('HOLYMD_BROWSER_TIMEOUT_MS=' . $previous);
        }
    }

    public function test_browser_fixture_retries_only_a_transient_endpoint_readiness_failure(): void
    {
        self::assertTrue($this->shouldRetryBrowserFailure(['exitCode' => 1, 'stdout' => '', 'stderr' => 'DevTools endpoint readiness timed out: fetch failed', 'timedOut' => false], 0));
        self::assertFalse($this->shouldRetryBrowserFailure(['exitCode' => 1, 'stdout' => '', 'stderr' => 'Page.loadEventFired timed out', 'timedOut' => false], 0));
        self::assertFalse($this->shouldRetryBrowserFailure(['exitCode' => 1, 'stdout' => '', 'stderr' => 'DevTools endpoint readiness timed out: fetch failed', 'timedOut' => false], 1));
    }

    public function test_multilingual_generated_artifacts_are_valid_and_never_introduce_replacement_characters(): void
    {
        $article = new ArticleDocument(
            'utf8-integrity',
            '中文、emoji 与 UTF-8 ✅',
            "## 标题\n\n正文包含弯引号“”、破折号——和图片。\n\n![清晰替代文本](/media/example.png)\n",
            new FrontMatter([
                'title' => '中文、emoji 与 UTF-8 ✅', 'slug' => 'utf8-integrity', 'date' => '2026-08-17',
                'summary' => '这是一段用于验证多语言静态产物字符完整性的详细摘要。', 'topics' => ['测试'],
            ]),
            '/utf8-integrity',
        );
        (new StaticBuilder())->build($this->input([$article], '中文站点', 'https://example.test', '作者', '关于作者', true), $this->outputRoot);

        foreach (['index.html', 'articles/utf8-integrity/index.html', 'feed.json', 'search-index.json', 'llms.txt', 'llms-full.txt'] as $path) {
            $contents = (string) file_get_contents($this->outputRoot . '/' . $path);
            self::assertTrue(mb_check_encoding($contents, 'UTF-8'), $path);
            self::assertStringNotContainsString("\u{FFFD}", $contents, $path);
        }
        json_decode((string) file_get_contents($this->outputRoot . '/feed.json'), true, flags: JSON_THROW_ON_ERROR);
        json_decode((string) file_get_contents($this->outputRoot . '/search-index.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string((string) file_get_contents($this->outputRoot . '/rss.xml')));
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string((string) file_get_contents($this->outputRoot . '/atom.xml')));
    }

    public function test_each_article_is_rendered_exactly_once_per_build(): void
    {
        $counter = new class extends \HolyMD\Render\MarkdownRenderer {
            public int $calls = 0;
            public function __construct() {}
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
        (new StaticBuilder(null, $counter))->build($this->input($articles, 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        self::assertSame(2, $counter->calls);
        $feed = (string) file_get_contents($this->outputRoot . '/feed.json');
        self::assertSame(2, substr_count($feed, '"content_html": "<p>rendered</p>"'));
    }

    public function test_injected_build_timestamp_drives_feed_and_sitemap_freshness_signals(): void
    {
        $article = new ArticleDocument('fresh', 'Fresh', 'Body.', new FrontMatter(['title' => 'Fresh', 'slug' => 'fresh', 'date' => '2026-08-12', 'updated' => '2026-08-10']), '/fresh');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', false, 'zh-CN', '2026-08-13T04:00:00+00:00'), $this->outputRoot);

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
        $page = new ArticleDocument('about', 'About', 'Body.', new FrontMatter(['title' => 'About', 'slug' => 'about', 'date' => '2026-08-12', 'nav_order' => 1]), '/about');
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test/holymd', 'Author', 'About', false, 'zh-CN', null, '/holymd', [$page]), $this->outputRoot);

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
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

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
        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

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
            (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);
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

        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

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

        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

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

        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', true), $this->outputRoot);

        $full = (string) file_get_contents($this->outputRoot . '/llms-full.txt');
        self::assertStringNotContainsString('Author: unknown', $full);
        self::assertStringNotContainsString('Maybe cite a paper.', $full);
        self::assertStringNotContainsString('Article author unknown', $full);
    }

    public function test_reading_time_uses_words_for_latin_text_and_characters_for_cjk_text(): void
    {
        $english = new ArticleDocument('english-time', 'English time', implode(' ', array_fill(0, 300, 'word')), new FrontMatter(['title' => 'English time', 'slug' => 'english-time', 'date' => '2026-08-12']), '/english-time');
        $chinese = new ArticleDocument('chinese-time', 'Chinese time', str_repeat('字', 300), new FrontMatter(['title' => 'Chinese time', 'slug' => 'chinese-time', 'date' => '2026-08-11']), '/chinese-time');
        (new StaticBuilder())->build($this->input([$english, $chinese], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

        self::assertStringContainsString('2 min read', (string) file_get_contents($this->outputRoot . '/articles/english-time/index.html'));
        self::assertStringContainsString('1 min read', (string) file_get_contents($this->outputRoot . '/articles/chinese-time/index.html'));
    }

    public function test_extracts_og_image_from_front_matter_or_body(): void
    {
        $coverArticle = new ArticleDocument('cover-post', 'Cover Post', 'Body without images.', new FrontMatter(['title' => 'Cover Post', 'slug' => 'cover-post', 'date' => '2026-08-12', 'cover_image' => '/media/hero.jpg']), '/cover-post');
        $bodyImageArticle = new ArticleDocument('body-post', 'Body Post', 'Some text and ![photo](/media/photo.png).', new FrontMatter(['title' => 'Body Post', 'slug' => 'body-post', 'date' => '2026-08-11']), '/body-post');
        $noImageArticle = new ArticleDocument('no-image', 'No Image', 'Just pure text.', new FrontMatter(['title' => 'No Image', 'slug' => 'no-image', 'date' => '2026-08-10']), '/no-image');

        (new StaticBuilder())->build($this->input([$coverArticle, $bodyImageArticle, $noImageArticle], 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

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

        (new StaticBuilder())->build($this->input($articles, 'Site', 'https://example.test', 'Author', 'About'), $this->outputRoot);

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

        (new StaticBuilder())->build($this->input([$article], 'Site', 'https://example.test', 'Author', 'About', false, 'zh-CN', null, '', [$page1, $page2, $draftPage]), $this->outputRoot);

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

    /**
     * @param list<ArticleDocument> $articles
     * @param list<ArticleDocument> $pages
     */
    private function input(
        array $articles,
        string $siteName,
        string $siteUrl,
        string $authorName,
        string $about,
        bool $generateLlmsTxt = false,
        string $siteLanguage = 'zh-CN',
        ?string $builtAt = null,
        string $basePath = '',
        array $pages = [],
    ): BuildInput {
        return new BuildInput(
            $articles,
            new PublicationSettings($siteName, $siteUrl, $authorName, $about, $generateLlmsTxt, $siteLanguage, $basePath),
            $builtAt,
            $pages,
        );
    }

    private function buildResponsiveBrowserArticle(): void
    {
        $markdown = "# Overview\n\nOpening paragraph with an [inline prose link](https://example.org/context).\n\n## Detail\n\nDetailed paragraph.\n\n### Fine point\n\nFine detail.\n\n![First image](/media/first.png)\n\n![Second image](/media/second.png)";
        $article = new ArticleDocument(
            'responsive-browser',
            'Responsive browser fixture',
            $markdown,
            new FrontMatter([
                'title' => 'Responsive browser fixture',
                'slug' => 'responsive-browser',
                'date' => '2026-08-12',
                'summary' => 'A generated route fixture that exposes every public reading control.',
                'topics' => ['Responsive design'],
                'sources' => ['https://example.org/evidence'],
                'internal_links' => ['/about/'],
                'faq' => [['question' => 'Does the layout remain usable?', 'answer' => 'Yes.']],
            ]),
            '/responsive-browser',
        );
        $older = new ArticleDocument(
            'older-responsive-note',
            'Older responsive note',
            'A short related note.',
            new FrontMatter(['title' => 'Older responsive note', 'slug' => 'older-responsive-note', 'date' => '2026-08-11', 'summary' => 'A second item exposes the article-row arrow.', 'topics' => ['Responsive design']]),
            '/older-responsive-note',
        );
        $about = new ArticleDocument('about', 'About', 'About the author and [published work](/).', new FrontMatter(['title' => 'About', 'slug' => 'about', 'date' => '2026-08-12', 'nav_order' => 1]), '/about');
        $articles = [$article, $older];
        for ($index = 1; $index <= 10; $index++) {
            $slug = 'archive-note-' . $index;
            $articles[] = new ArticleDocument($slug, 'Archive note ' . $index, 'A pagination fixture.', new FrontMatter(['title' => 'Archive note ' . $index, 'slug' => $slug, 'date' => '2026-07-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'summary' => 'A fixture that makes the Load more control observable.']), '/' . $slug);
        }
        (new StaticBuilder())->build(
            $this->input($articles, 'HolyMarkdownPublicationWithAnIntentionallyLongConfigurableName', 'https://example.test', 'Author', 'About', false, 'en', null, '', [$about]),
            $this->outputRoot,
        );
    }

    private function buildConfiguredBrandBrowserArticle(): void
    {
        $article = new ArticleDocument(
            'responsive-browser',
            'Responsive browser fixture',
            'Generated article body.',
            new FrontMatter(['title' => 'Responsive browser fixture', 'slug' => 'responsive-browser', 'date' => '2026-08-12']),
            '/responsive-browser',
        );
        (new StaticBuilder())->build(
            $this->input([$article], "Corlin's Blog", 'https://example.test', 'Corlin', 'About Corlin.'),
            $this->outputRoot,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function browserMetrics(int $viewportWidth, string $bodyScript, string $headScript = '', string $route = '/articles/responsive-browser/index.html', bool $exerciseProductionSearch = false): array
    {
        $chrome = $this->resolveBrowserExecutable();
        $node = $this->resolveNodeExecutable();
        if ($chrome === null || $node === null) {
            $missing = implode(' and ', array_filter([$chrome === null ? 'Chrome/Chromium' : null, $node === null ? 'Node.js 22+' : null]));
            $message = 'Browser regression prerequisite missing: ' . $missing . '. Set HOLYMD_CHROME_BIN and ensure Node.js 22 or newer is on PATH.';
            if ($this->browserCoverageRequired() || getenv('HOLYMD_CHROME_BIN') !== false || getenv('HOLYMD_NODE_BIN') !== false) {
                self::fail($message);
            }
            self::markTestSkipped('REDUCED COVERAGE: ' . $message);
        }

        $version = $this->runProcess([$node, '--version'], 5000);
        preg_match('/v?(\d+)/', $version['stdout'], $versionMatch);
        if ($version['exitCode'] !== 0 || (int) ($versionMatch[1] ?? 0) < 22) {
            self::fail('Browser regression prerequisite unsupported: Node.js 22 or newer is required; ' . $node . ' reported ' . trim($version['stdout'] . ' ' . $version['stderr']));
        }
        $webPlatform = $this->runProcess([$node, '-e', "process.exit(typeof fetch === 'function' && typeof WebSocket === 'function' ? 0 : 1)"], 5000);
        if ($webPlatform['exitCode'] !== 0) {
            self::fail('Browser regression prerequisite unsupported: Node.js 22 or newer with global fetch and WebSocket is required (' . $node . ').');
        }

        $html = (string) file_get_contents($this->outputRoot . $route);
        $styles = $this->generatedPublicStyles();
        $html = (string) preg_replace('/<link rel="stylesheet"[^>]+>/', '', $html);
        $html = (string) preg_replace('/<script src="[^"]+" defer><\/script>/', '', $html);
        $styles = str_replace("url('fonts/", "url('assets/fonts/", $styles);
        $fontSource = dirname(__DIR__, 2) . '/public/assets/fonts/material-symbols-outlined-v2.woff2';
        $fontDirectory = $this->outputRoot . '/assets/fonts';
        self::assertFileExists($fontSource, 'browser fixture must use the checked-in self-hosted icon font');
        if (!is_dir($fontDirectory)) {
            self::assertTrue(mkdir($fontDirectory, 0777, true), 'unable to create browser fixture font directory');
        }
        self::assertTrue(copy($fontSource, $fontDirectory . '/material-symbols-outlined-v2.woff2'), 'unable to copy the self-hosted icon font into the browser fixture');
        $productionSearch = '';
        if ($exerciseProductionSearch) {
            // file:// fixtures cannot fetch the generated index reliably. Stub only that response,
            // then execute the unmodified emitted search asset against the generated page DOM.
            $searchIndex = json_decode((string) file_get_contents($this->outputRoot . '/search-index.json'), true, flags: JSON_THROW_ON_ERROR);
            $encodedIndex = json_encode($searchIndex, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $headScript .= '<script>window.__requestedSearchIndex=null;window.fetch=function(url){window.__requestedSearchIndex=typeof url==="string"?url:url.url;if(window.__requestedSearchIndex!=="/search-index.json")return Promise.reject(new Error("Unexpected search index URL: "+window.__requestedSearchIndex));return Promise.resolve({ok:true,json:function(){return Promise.resolve(' . $encodedIndex . ');}});};</script>';
            $searchAssets = glob($this->outputRoot . '/assets/search.*.js') ?: [];
            self::assertCount(1, $searchAssets, 'build must emit exactly one production search asset');
            $productionSearch = '<script>' . (string) file_get_contents($searchAssets[0]) . '</script>';
        }
        $html = str_replace('</head>', '<style>' . $styles . '</style>' . $headScript . '</head>', $html);
        $html = str_replace('</body>', $productionSearch . $bodyScript . '</body>', $html);
        $fixturePath = $this->outputRoot . '/responsive-' . $viewportWidth . '-' . substr(hash('sha256', $route . $headScript . $bodyScript), 0, 8) . '.html';
        file_put_contents($fixturePath, $html);
        $runner = dirname(__DIR__) . '/Browser/chrome-metrics.mjs';
        $processTimeoutMilliseconds = $this->browserProcessTimeoutMilliseconds();
        $attempt = 0;
        do {
            $result = $this->runProcess([$node, $runner, $chrome, $fixturePath, (string) $viewportWidth], $processTimeoutMilliseconds);
        } while ($this->shouldRetryBrowserFailure($result, $attempt++));
        self::assertSame(
            0,
            $result['exitCode'],
            'headless Chrome must render the generated responsive fixture using ' . $chrome
            . ($result['timedOut'] ? "\nTimed out after {$processTimeoutMilliseconds}ms." : '')
            . ($result['stderr'] !== '' ? "\nstderr:\n" . $result['stderr'] : '')
            . ($result['stdout'] !== '' ? "\nstdout:\n" . $result['stdout'] : ''),
        );
        return json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function browserProcessTimeoutMilliseconds(): int
    {
        $phaseTimeoutMilliseconds = 15000;
        $configured = getenv('HOLYMD_BROWSER_TIMEOUT_MS');
        if ($configured !== false && ctype_digit($configured)) {
            $validated = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 2147483647]]);
            if ($validated !== false) {
                $phaseTimeoutMilliseconds = $validated;
            }
        }

        // The runner can consume several sequential phase budgets before its bounded teardown.
        $maximumPhaseTimeout = intdiv(PHP_INT_MAX - 5000, 10);
        if ($phaseTimeoutMilliseconds > $maximumPhaseTimeout) {
            return PHP_INT_MAX;
        }
        return ($phaseTimeoutMilliseconds * 10) + 5000;
    }

    /** @param array{exitCode: int, stdout: string, stderr: string, timedOut: bool} $result */
    private function shouldRetryBrowserFailure(array $result, int $completedRetries): bool
    {
        return $completedRetries === 0
            && $result['exitCode'] !== 0
            && !$result['timedOut']
            && str_contains($result['stderr'], 'DevTools endpoint readiness timed out');
    }

    private function resolveBrowserExecutable(): ?string
    {
        $configured = getenv('HOLYMD_CHROME_BIN');
        if ($configured !== false && $configured !== '') {
            return is_executable($configured) ? $configured : null;
        }
        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $binary) {
            $resolved = $this->resolveFromPath($binary);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        foreach (['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium', '/usr/bin/google-chrome', '/usr/bin/chromium', '/snap/bin/chromium'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function resolveNodeExecutable(): ?string
    {
        $configured = getenv('HOLYMD_NODE_BIN');
        if ($configured !== false && $configured !== '') {
            return is_executable($configured) ? $configured : null;
        }
        return $this->resolveFromPath('node');
    }

    private function resolveFromPath(string $binary): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function browserCoverageRequired(): bool
    {
        return getenv('HOLYMD_BROWSER_REQUIRED') === '1' || !in_array(getenv('CI'), [false, '', '0'], true);
    }

    /** @param list<string> $arguments @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool} */
    private function runProcess(array $arguments, int $timeoutMilliseconds): array
    {
        $pipes = [];
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'unable to start browser regression prerequisite');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        $timedOut = false;
        $exitCode = -1;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                usleep(200000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(20000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode < 0 && $closedExitCode >= 0) {
            $exitCode = $closedExitCode;
        }
        return ['exitCode' => $timedOut ? 124 : $exitCode, 'stdout' => trim($stdout), 'stderr' => trim($stderr), 'timedOut' => $timedOut];
    }

    /** @return array{light: array<string, string>, dark: array<string, string>, systemDark: array<string, string>} */
    private function cssTokenScopes(string $styles): array
    {
        $patterns = [
            'light' => '/:root\s*\{(?<block>.*?)\}/s',
            'dark' => '/html\[data-theme="dark"\]\s*\{(?<block>.*?)\}/s',
            'systemDark' => '/@media \(prefers-color-scheme: dark\)\s*\{\s*html:not\(\[data-theme="light"\]\)\s*\{(?<block>.*?)\}\s*\}/s',
        ];
        $scopes = [];
        foreach ($patterns as $name => $pattern) {
            $matched = preg_match_all($pattern, $styles, $matches);
            self::assertSame(1, $matched, 'semantic token scope ' . $name . ' must be declared exactly once');
            $scopes[$name] = $this->cssCustomProperties($matches['block'][0]);
        }

        return $scopes;
    }

    private function generatedPublicStyles(): string
    {
        $assets = glob($this->outputRoot . '/assets/site.*.css') ?: [];
        self::assertCount(1, $assets, 'build must emit exactly one content-hashed public stylesheet');
        return (string) file_get_contents($assets[0]);
    }

    /** @return array<string, string> */
    private function cssCustomProperties(string $block): array
    {
        preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);
        $names = array_column($matches, 1);
        self::assertSame(count($names), count(array_unique($names)), 'semantic token names must not be duplicated within a scope');
        $properties = [];
        foreach ($matches as $match) {
            $properties[$match[1]] = trim($match[2]);
        }
        ksort($properties);
        return $properties;
    }

    private function contrastRatio(string $firstHex, string $secondHex): float
    {
        $first = $this->relativeLuminance($firstHex);
        $second = $this->relativeLuminance($secondHex);
        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $hex);
        $channels = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);
        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}
