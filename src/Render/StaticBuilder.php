<?php

declare(strict_types=1);

namespace HolyMD\Render;

use DateTimeImmutable;
use HolyMD\Content\ArticleDocument;
use RuntimeException;

final class StaticBuilder
{
    private TemplateRenderer $renderer;
    private MarkdownRendererInterface $markdownRenderer;

    public function __construct(?TemplateRenderer $renderer = null, ?MarkdownRendererInterface $markdownRenderer = null)
    {
        $this->renderer = $renderer ?? new TemplateRenderer(dirname(__DIR__, 2) . '/templates/public');
        $this->markdownRenderer = $markdownRenderer ?? new MarkdownRenderer();
    }

    public function build(BuildInput $input, string $temporaryRoot): BuildManifest
    {
        if (!str_starts_with($input->siteUrl, 'https://')) {
            throw new RuntimeException('The public site URL must use HTTPS.');
        }
        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $input->siteLanguage) !== 1) {
            throw new RuntimeException('The public site language must be a valid BCP 47 language tag.');
        }
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
            throw new RuntimeException('Unable to create temporary build directory.');
        }
        $articles = $input->articles;
        $slugs = [];
        foreach ($articles as $article) {
            if (isset($slugs[$article->slug])) throw new RuntimeException(sprintf('Duplicate article slug "%s".', $article->slug));
            $slugs[$article->slug] = true;
        }
        usort($articles, static fn (ArticleDocument $a, ArticleDocument $b): int => strcmp((string) $b->frontMatter->get('date'), (string) $a->frontMatter->get('date')));
        $builtAt = $input->builtAt ?? gmdate(DATE_ATOM);
        $styles = $this->siteStyles();
        $script = $this->siteScript();
        $cssHash = substr(hash('sha256', $styles), 0, 10);
        $scriptHash = substr(hash('sha256', $script), 0, 10);
        $assetCss = $input->basePath . '/assets/site.' . $cssHash . '.css';
        $assetSearch = $input->basePath . '/assets/search.' . $scriptHash . '.js';
        $files = [];

        $publishedPages = array_values(array_filter($input->pages, static fn (ArticleDocument $p): bool => $p->frontMatter->get('status', 'published') === 'published'));
        $navCandidatePages = array_values(array_filter($publishedPages, static fn (ArticleDocument $p): bool => $p->frontMatter->get('nav_order') !== null && is_numeric($p->frontMatter->get('nav_order'))));
        usort($navCandidatePages, static fn (ArticleDocument $a, ArticleDocument $b): int => ((float) $a->frontMatter->get('nav_order')) <=> ((float) $b->frontMatter->get('nav_order')));
        $navPages = array_map(static fn (ArticleDocument $p): array => ['title' => $p->title, 'slug' => $p->slug], $navCandidatePages);

        $topics = [];
        foreach ($articles as $article) {
            foreach ((array) $article->frontMatter->get('topics', []) as $topic) {
                if (!is_string($topic) || trim($topic) === '') throw new RuntimeException(sprintf('Article "%s" has an invalid topic.', $article->slug));
                $topics[$topic][] = $article;
            }
        }
        $topicRoutes = [];
        $topicSlugs = [];
        foreach ($topics as $topic => $topicArticles) {
            $topicLabel = (string) $topic;
            $slug = $this->topicSlug($topicLabel);
            if (isset($topicRoutes[$slug]) && $topicRoutes[$slug] !== $topicLabel) throw new RuntimeException(sprintf('Topic "%s" has an unsafe or colliding slug.', $topicLabel));
            $topicRoutes[$slug] = $topicLabel;
            $topicSlugs[$topicLabel] = $slug;
            $route = '/topics/' . $slug . '/';
            $this->write($temporaryRoot . $route . 'index.html', $this->renderer->render('topic', [
                'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'siteLanguage' => $input->siteLanguage, 'topic' => $topicLabel, 'articles' => $topicArticles, 'route' => $route, 'generateLlmsTxt' => $input->generateLlmsTxt, 'assetCss' => $assetCss, 'assetSearch' => $assetSearch, 'basePath' => $input->basePath, 'navPages' => $navPages,
            ]));
            $files[] = $route . 'index.html';
        }
        $rendered = [];
        foreach ($articles as $article) {
            $route = '/articles/' . $article->slug . '/';
            $path = $temporaryRoot . $route . 'index.html';
            $data = $this->articleData($article, $articles, $input, $route, $topicSlugs, $assetCss, $assetSearch, $navPages);
            $this->write($path, $this->renderer->render('article', $data));
            $files[] = $route . 'index.html';
            $rendered[] = $data;
        }
        foreach ($publishedPages as $page) {
            $route = '/' . $page->slug . '/';
            $path = $temporaryRoot . $route . 'index.html';
            $contentHtml = $this->applyImageAltText($this->markdownRenderer->render($page->bodyMarkdown), $this->stringList($page->frontMatter->get('alt_text')));
            $this->write($path, $this->renderer->render('page', [
                'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'siteLanguage' => $input->siteLanguage,
                'page' => $page, 'contentHtml' => $contentHtml, 'generateLlmsTxt' => $input->generateLlmsTxt,
                'assetCss' => $assetCss, 'assetSearch' => $assetSearch, 'basePath' => $input->basePath, 'navPages' => $navPages,
            ]));
            $files[] = substr($route, 1) . 'index.html';
        }
        $this->write($temporaryRoot . '/index.html', $this->renderer->render('index', [
            'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'about' => $input->about, 'siteLanguage' => $input->siteLanguage, 'articles' => $articles, 'topics' => $topics, 'topicSlugs' => $topicSlugs, 'generateLlmsTxt' => $input->generateLlmsTxt, 'assetCss' => $assetCss, 'assetSearch' => $assetSearch, 'basePath' => $input->basePath, 'navPages' => $navPages,
        ]));
        $this->write($temporaryRoot . '/about/index.html', $this->renderer->render('about', ['siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'about' => $input->about, 'siteLanguage' => $input->siteLanguage, 'generateLlmsTxt' => $input->generateLlmsTxt, 'assetCss' => $assetCss, 'assetSearch' => $assetSearch, 'basePath' => $input->basePath, 'navPages' => $navPages]));
        $this->write($temporaryRoot . '/404.html', $this->renderer->render('404', ['siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'siteLanguage' => $input->siteLanguage, 'generateLlmsTxt' => $input->generateLlmsTxt, 'assetCss' => $assetCss, 'assetSearch' => $assetSearch, 'basePath' => $input->basePath, 'navPages' => $navPages]));
        $this->write($temporaryRoot . $assetCss, $styles);
        $this->write($temporaryRoot . $assetSearch, $script);
        $this->write($temporaryRoot . '/rss.xml', $this->rss($articles, $input, $builtAt));
        $this->write($temporaryRoot . '/atom.xml', $this->atom($articles, $input, $builtAt));
        $this->write($temporaryRoot . '/feed.json', $this->jsonFeed($rendered, $input));
        $this->write($temporaryRoot . '/sitemap.xml', $this->sitemap($articles, $publishedPages, $input, array_keys($topicRoutes), $builtAt));
        $this->write($temporaryRoot . '/search-index.json', $this->searchIndex($rendered, $builtAt));
        $robotsTxt = "User-agent: *\nAllow: /\nSitemap: " . $this->url($input, '/sitemap.xml') . "\n";
        if ($input->generateLlmsTxt) {
            $robotsTxt .= "LLMs-Txt: " . $this->url($input, '/llms.txt') . "\nLLMs-Full-Txt: " . $this->url($input, '/llms-full.txt') . "\n";
        }
        $this->write($temporaryRoot . '/robots.txt', $robotsTxt);
        $files = [...$files, 'index.html', 'about/index.html', '404.html', substr($assetCss, 1), substr($assetSearch, 1), 'rss.xml', 'atom.xml', 'feed.json', 'sitemap.xml', 'search-index.json', 'robots.txt'];
        if ($input->generateLlmsTxt) {
            $lines = ['# ' . $input->siteName, '', $input->about, ''];
            foreach ($articles as $article) {
                $summary = (string) $article->frontMatter->get('summary', '');
                $line = '- [' . $this->llmsTitle($article->title) . '](' . $this->url($input, '/articles/' . $article->slug . '/') . ')';
                if ($summary !== '') {
                    $line .= ': ' . str_replace(["\r", "\n"], ' ', $summary);
                }
                $lines[] = $line;
            }
            $this->write($temporaryRoot . '/llms.txt', implode("\n", $lines) . "\n");
            $files[] = 'llms.txt';

            $fullLines = ['# ' . $input->siteName . ' (Full Archive)', '', $input->about, ''];
            foreach ($articles as $article) {
                $fullLines[] = '---';
                $fullLines[] = '# ' . $this->llmsTitle($article->title);
                $fullLines[] = 'Published: ' . (string) $article->frontMatter->get('date');
                $fullLines[] = 'URL: ' . $this->url($input, '/articles/' . $article->slug . '/');
                $summary = (string) $article->frontMatter->get('summary', '');
                if ($summary !== '') {
                    $fullLines[] = 'Summary: ' . str_replace(["\r", "\n"], ' ', $summary);
                }
                $topics = (array) $article->frontMatter->get('topics', []);
                if ($topics !== []) {
                    $fullLines[] = 'Topics: ' . implode(', ', array_map('strval', $topics));
                }
                $entities = $article->frontMatter->get('entities');
                $entityText = $this->metadataText($entities, ', ');
                if ($entityText !== null) $fullLines[] = 'Entities: ' . $entityText;
                $faq = $article->frontMatter->get('faq');
                $faqText = $this->metadataText($faq, ' | ');
                if ($faqText !== null) $fullLines[] = 'FAQ: ' . $faqText;
                $fullLines[] = '';
                $fullLines[] = trim($article->bodyMarkdown);
                $fullLines[] = '';
            }
            $this->write($temporaryRoot . '/llms-full.txt', implode("\n", $fullLines) . "\n");
            $files[] = 'llms-full.txt';
        }
        return new BuildManifest(count($articles), $files);
    }

    /** @return array<string, mixed> */
    /**
     * @param list<ArticleDocument> $articles
     * @param list<array{title: string, slug: string}> $navPages
     */
    private function articleData(ArticleDocument $article, array $articles, BuildInput $input, string $route, array $topicSlugs, string $assetCss, string $assetSearch, array $navPages = []): array
    {
        $url = $this->url($input, $route);
        $date = (string) $article->frontMatter->get('date');
        $modified = (string) $article->frontMatter->get('updated', $date);
        $summary = (string) $article->frontMatter->get('summary', '');
        $sources = array_values(array_filter((array) $article->frontMatter->get('sources', []), 'is_string'));
        $internalLinks = array_values(array_filter((array) $article->frontMatter->get('internal_links', []), 'is_string'));
        $entities = $this->stringList($article->frontMatter->get('entities'));
        $faq = $this->faqEntries($article->frontMatter->get('faq'));
        $structured = $article->frontMatter->get('structured_data');
        $contentHtml = $this->applyImageAltText($this->markdownRenderer->render($article->bodyMarkdown), $this->stringList($article->frontMatter->get('alt_text')));
        $ogImage = $this->resolveOgImage($article, $contentHtml, $input);
        $articleSchema = ['@type' => 'Article', 'headline' => $article->title, 'datePublished' => $date, 'dateModified' => $modified, 'author' => ['@type' => 'Person', 'name' => $input->authorName], 'mainEntityOfPage' => $url, 'description' => $summary, 'citation' => $sources, 'about' => array_map(static fn (string $name): array => ['@type' => 'Thing', 'name' => $name], $entities)];
        if ($ogImage !== null) {
            $articleSchema['image'] = $ogImage;
        }
        $graph = [
                $articleSchema,
                ['@type' => 'Person', 'name' => $input->authorName],
                ['@type' => 'WebSite', 'name' => $input->siteName, 'url' => rtrim($input->siteUrl, '/') . '/'],
                ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $this->url($input, '/')], ['@type' => 'ListItem', 'position' => 2, 'name' => $article->title, 'item' => $url]]],
        ];
        if ($faq !== []) $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(static fn (array $entry): array => ['@type' => 'Question', 'name' => $entry['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $entry['answer']]], $faq)];
        if (is_array($structured)) $graph[] = $structured;
        $articleTopics = array_values(array_filter((array) $article->frontMatter->get('topics', []), 'is_string'));
        $related = array_values(array_filter($articles, static function (ArticleDocument $candidate) use ($article, $articleTopics): bool {
            if ($candidate->slug === $article->slug || $articleTopics === []) return false;
            return array_intersect($articleTopics, (array) $candidate->frontMatter->get('topics', [])) !== [];
        }));
        $feedContentHtml = $contentHtml;
        $searchText = mb_substr(html_entity_decode(strip_tags($contentHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 12288);
        $toc = [];
        $usedHeadingIds = [];
        $headingIdSequences = [];
        $contentHtmlWithIds = preg_replace_callback(
            '/<h([234])(\b[^>]*)>(.*?)<\/h\1>/s',
            static function (array $matches) use (&$toc, &$usedHeadingIds, &$headingIdSequences): string {
                $level = (int) $matches[1];
                $attrs = $matches[2];
                $innerHtml = $matches[3];
                $plainText = trim(html_entity_decode(strip_tags($innerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($plainText === '') return $matches[0];
                $baseId = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($plainText)), '-');
                if ($baseId === '') $baseId = 'heading-' . (count($toc) + 1);
                $sequence = ($headingIdSequences[$baseId] ?? 0) + 1;
                $id = $sequence === 1 ? $baseId : $baseId . '-' . $sequence;
                while (isset($usedHeadingIds[$id])) {
                    $sequence++;
                    $id = $baseId . '-' . $sequence;
                }
                $headingIdSequences[$baseId] = $sequence;
                $usedHeadingIds[$id] = true;
                $toc[] = ['level' => $level, 'title' => $plainText, 'id' => $id];
                return sprintf('<h%d id="%s"%s>%s</h%d>', $level, $id, $attrs, $innerHtml, $level);
            },
            $contentHtml
        ) ?? $contentHtml;

        $readingMinutes = $this->readingMinutes($contentHtml);

        return [
            'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'siteLanguage' => $input->siteLanguage, 'article' => $article,
            'url' => $url, 'date' => $date, 'modified' => $modified, 'summary' => $summary, 'sources' => $sources, 'internalLinks' => $internalLinks, 'faq' => $faq, 'topics' => $articleTopics, 'topicSlugs' => $topicSlugs, 'related' => array_slice($related, 0, 3),
            'contentHtml' => $contentHtmlWithIds, 'feedContentHtml' => $feedContentHtml, 'searchText' => $searchText, 'toc' => $toc, 'readingMinutes' => $readingMinutes, 'ogImage' => $ogImage, 'generateLlmsTxt' => $input->generateLlmsTxt, 'assetCss' => $assetCss, 'assetSearch' => $assetSearch, 'basePath' => $input->basePath, 'navPages' => $navPages,
            'jsonLd' => json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        ];
    }

    /** @param list<ArticleDocument> $articles */
    private function rss(array $articles, BuildInput $input, string $builtAt): string
    {
        $items = array_map(function (ArticleDocument $article) use ($input): string {
            $url = $this->url($input, '/articles/' . $article->slug . '/');
            return '<item><title>' . $this->xml($article->title) . '</title><link>' . $this->xml($url) . '</link><guid>' . $this->xml($url) . '</guid><pubDate>' . $this->date((string) $article->frontMatter->get('date')) . '</pubDate><description>' . $this->xml((string) $article->frontMatter->get('summary', '')) . '</description></item>';
        }, $articles);
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . $this->xml($input->siteName) . '</title><link>' . $this->xml($input->siteUrl) . '</link><description>' . $this->xml($input->about) . '</description><lastBuildDate>' . $this->date($builtAt) . '</lastBuildDate>' . implode('', $items) . '</channel></rss>';
    }

    /** @param list<ArticleDocument> $articles */
    private function atom(array $articles, BuildInput $input, string $builtAt): string
    {
        $feedUrl = $this->url($input, '/atom.xml');
        $siteUrl = rtrim($input->siteUrl, '/') . '/';
        $updated = (new DateTimeImmutable($builtAt))->format(DATE_ATOM);
        $entries = array_map(function (ArticleDocument $article) use ($input): string {
            $url = $this->url($input, '/articles/' . $article->slug . '/');
            $date = (string) $article->frontMatter->get('date');
            $published = (new DateTimeImmutable($date))->format(DATE_ATOM);
            $modified = (string) $article->frontMatter->get('updated', $date);
            $updatedDate = (new DateTimeImmutable($modified))->format(DATE_ATOM);
            $summary = (string) $article->frontMatter->get('summary', '');
            $summaryTag = $summary !== '' ? '<summary>' . $this->xml($summary) . '</summary>' : '';
            return '<entry><title>' . $this->xml($article->title) . '</title><link href="' . $this->xml($url) . '"/><id>' . $this->xml($url) . '</id><published>' . $published . '</published><updated>' . $updatedDate . '</updated>' . $summaryTag . '<author><name>' . $this->xml($input->authorName) . '</name></author></entry>';
        }, $articles);
        return '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>' . $this->xml($input->siteName) . '</title><subtitle>' . $this->xml($input->about) . '</subtitle><link href="' . $this->xml($feedUrl) . '" rel="self"/><link href="' . $this->xml($siteUrl) . '"/><id>' . $this->xml($siteUrl) . '</id><updated>' . $updated . '</updated><author><name>' . $this->xml($input->authorName) . '</name></author>' . implode('', $entries) . '</feed>';
    }

    /** @param list<array<string, mixed>> $rendered */
    private function jsonFeed(array $rendered, BuildInput $input): string
    {
        $items = array_map(function (array $data) use ($input): array {
            $article = $data['article'];
            return ['id' => $this->url($input, '/articles/' . $article->slug . '/'), 'url' => $this->url($input, '/articles/' . $article->slug . '/'), 'title' => $article->title, 'date_published' => (string) $article->frontMatter->get('date'), 'date_modified' => (string) $data['modified'], 'summary' => (string) $article->frontMatter->get('summary', ''), 'content_html' => (string) $data['feedContentHtml'], 'authors' => [['name' => $input->authorName]]];
        }, $rendered);
        return json_encode(['version' => 'https://jsonfeed.org/version/1.1', 'title' => $input->siteName, 'home_page_url' => $input->siteUrl, 'feed_url' => $this->url($input, '/feed.json'), 'authors' => [['name' => $input->authorName]], 'items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param list<ArticleDocument> $articles
     * @param list<ArticleDocument> $pages
     */
    private function sitemap(array $articles, array $pages, BuildInput $input, array $topicSlugs, string $builtAt): string
    {
        $siteLastmod = substr($builtAt, 0, 10);
        $urls = [[$this->url($input, '/'), $siteLastmod], [$this->url($input, '/about/'), $siteLastmod]];
        foreach ($pages as $page) {
            $urls[] = [$this->url($input, '/' . $page->slug . '/'), (string) $page->frontMatter->get('updated', (string) $page->frontMatter->get('date'))];
        }
        foreach ($articles as $article) {
            $urls[] = [$this->url($input, '/articles/' . $article->slug . '/'), (string) $article->frontMatter->get('updated', (string) $article->frontMatter->get('date'))];
        }
        foreach ($topicSlugs as $topicSlug) $urls[] = [$this->url($input, '/topics/' . $topicSlug . '/'), $siteLastmod];
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', array_map(fn (array $entry): string => '<url><loc>' . $this->xml($entry[0]) . '</loc><lastmod>' . $this->xml($entry[1]) . '</lastmod></url>', $urls)) . '</urlset>';
    }

    /** @param list<array<string, mixed>> $rendered */
    private function searchIndex(array $rendered, string $builtAt): string
    {
        $articles = array_map(function (array $data): array {
            $article = $data['article'];
            return [
                'slug' => $article->slug,
                'title' => $article->title,
                'date' => (string) $article->frontMatter->get('date'),
                'updated' => (string) $article->frontMatter->get('updated', (string) $article->frontMatter->get('date')),
                'summary' => (string) $article->frontMatter->get('summary', ''),
                'topics' => array_values(array_filter((array) $article->frontMatter->get('topics', []), 'is_string')),
                'text' => (string) $data['searchText'],
            ];
        }, $rendered);
        return json_encode(['builtAt' => $builtAt, 'articles' => $articles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function siteStyles(): string
    {
        $path = dirname(__DIR__, 2) . '/templates/public/site.css';
        $styles = file_get_contents($path);
        if ($styles === false) throw new RuntimeException('Unable to read the public site stylesheet.');
        return $styles;
    }

    private function siteScript(): string
    {
        $path = dirname(__DIR__, 2) . '/templates/public/search.js';
        $script = file_get_contents($path);
        if ($script === false) throw new RuntimeException('Unable to read the public search script.');
        return $script;
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create build path "%s".', $directory));
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write build file "%s".', $path));
        }
    }

    private function url(BuildInput $input, string $path): string { return rtrim($input->siteUrl, '/') . $path; }
    private function topicSlug(string $topic): string
    {
        $base = trim((string) preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($topic)) ?? ''), '-');
        $losslessAscii = preg_match('/^[A-Za-z0-9]+(?:[ _-][A-Za-z0-9]+)*$/D', trim($topic)) === 1;
        if ($base !== '' && $losslessAscii) return $base;
        return ($base === '' ? 'topic' : $base) . '-' . substr(hash('sha256', $topic), 0, 10);
    }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function date(string $date): string { return (new DateTimeImmutable($date))->format(DATE_RSS); }
    private function metadataText(mixed $value, string $separator): ?string
    {
        if (is_string($value)) {
            $value = trim(str_replace(["\r", "\n"], ' ', $value));
            return $value === '' ? null : $value;
        }
        if (!is_array($value) || $value === []) return null;
        if (array_is_list($value) && array_reduce($value, static fn (bool $valid, mixed $item): bool => $valid && is_scalar($item), true)) {
            return implode($separator, array_map(static fn (mixed $item): string => (string) $item, $value));
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) return array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/u', $value) ?: []), static fn (string $item): bool => $item !== ''));
        if (!is_array($value)) return [];
        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    /** @return list<array{question:string,answer:string}> */
    private function faqEntries(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) return [];
        $entries = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !is_string($entry['question'] ?? null) || !is_string($entry['answer'] ?? null) || trim($entry['question']) === '' || trim($entry['answer']) === '') continue;
            $entries[] = ['question' => trim($entry['question']), 'answer' => trim($entry['answer'])];
        }
        return $entries;
    }

    /** @param list<string> $altText */
    private function applyImageAltText(string $html, array $altText): string
    {
        $index = 0;
        return preg_replace_callback('/<img\b[^>]*>/i', static function (array $match) use (&$index, $altText): string {
            $tag = $match[0];
            $replacement = $altText[$index++] ?? null;
            if (!is_string($replacement) || $replacement === '') return $tag;
            $escaped = htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (preg_match('/\balt=([' . "'\"" . '])\1/i', $tag) === 1) return (string) preg_replace('/\balt=([' . "'\"" . '])\1/i', 'alt="' . $escaped . '"', $tag, 1);
            if (preg_match('/\balt=/i', $tag) === 1) return $tag;
            return preg_replace('/\s*\/>$/', ' alt="' . $escaped . '" />', $tag) ?? $tag;
        }, $html) ?? $html;
    }

    private function llmsTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        return str_replace(['\\', '[', ']', '(', ')'], ['\\\\', '\\[', '\\]', '\\(', '\\)'], $title);
    }

    private function readingMinutes(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cjkPattern = '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u';
        $cjkCharacters = preg_match_all($cjkPattern, $text);
        $latinText = preg_replace($cjkPattern, ' ', $text) ?? '';
        $latinWords = preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\x{0027}-][\p{L}\p{N}]+)*/u', $latinText);
        $minutes = (($cjkCharacters === false ? 0 : $cjkCharacters) / 300) + (($latinWords === false ? 0 : $latinWords) / 200);
        return max(1, (int) ceil($minutes));
    }

    private function resolveOgImage(ArticleDocument $article, string $contentHtml, BuildInput $input): ?string
    {
        $cover = $article->frontMatter->get('cover_image');
        if (is_string($cover) && trim($cover) !== '') {
            return $this->absoluteImageUrl(trim($cover), $input);
        }
        if (preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/i', $contentHtml, $matches) === 1 && trim($matches[2]) !== '') {
            return $this->absoluteImageUrl(trim($matches[2]), $input);
        }
        return null;
    }

    private function absoluteImageUrl(string $src, BuildInput $input): string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }
        $path = '/' . ltrim($src, '/');
        return rtrim($input->siteUrl, '/') . $path;
    }
}
