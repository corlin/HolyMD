<?php

declare(strict_types=1);

namespace HolyMD\Render;

use DateTimeImmutable;
use HolyMD\Content\ArticleDocument;
use RuntimeException;

final class StaticBuilder
{
    private TemplateRenderer $renderer;

    public function __construct(?TemplateRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new TemplateRenderer(dirname(__DIR__, 2) . '/templates/public');
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
        $files = [];
        foreach ($articles as $article) {
            $route = '/articles/' . $article->slug . '/';
            $path = $temporaryRoot . $route . 'index.html';
            $this->write($path, $this->renderer->render('article', $this->articleData($article, $articles, $input, $route)));
            $files[] = $route . 'index.html';
        }
        $topics = [];
        foreach ($articles as $article) {
            foreach ((array) $article->frontMatter->get('topics', []) as $topic) {
                $topics[(string) $topic][] = $article;
            }
        }
        $topicRoutes = [];
        foreach ($topics as $topic => $topicArticles) {
            $slug = $this->topicSlug($topic);
            if ($slug === '' || (isset($topicRoutes[$slug]) && $topicRoutes[$slug] !== $topic)) throw new RuntimeException(sprintf('Topic "%s" has an unsafe or colliding slug.', $topic));
            $topicRoutes[$slug] = $topic;
            $route = '/topics/' . $slug . '/';
            $this->write($temporaryRoot . $route . 'index.html', $this->renderer->render('topic', [
                'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'siteLanguage' => $input->siteLanguage, 'topic' => $topic, 'articles' => $topicArticles, 'route' => $route,
            ]));
            $files[] = $route . 'index.html';
        }
        $this->write($temporaryRoot . '/index.html', $this->renderer->render('index', [
            'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'about' => $input->about, 'siteLanguage' => $input->siteLanguage, 'articles' => $articles, 'topics' => $topics,
        ]));
        $this->write($temporaryRoot . '/about/index.html', $this->renderer->render('about', ['siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'about' => $input->about, 'siteLanguage' => $input->siteLanguage]));
        $this->write($temporaryRoot . '/assets/site.css', $this->siteStyles());
        $this->write($temporaryRoot . '/rss.xml', $this->rss($articles, $input));
        $this->write($temporaryRoot . '/feed.json', $this->jsonFeed($articles, $input));
        $this->write($temporaryRoot . '/sitemap.xml', $this->sitemap($articles, $input, array_keys($topicRoutes)));
        $this->write($temporaryRoot . '/robots.txt', "User-agent: *\nAllow: /\nSitemap: " . $this->url($input, '/sitemap.xml') . "\n");
        $files = [...$files, 'index.html', 'about/index.html', 'assets/site.css', 'rss.xml', 'feed.json', 'sitemap.xml', 'robots.txt'];
        if ($input->generateLlmsTxt) {
            $lines = ['# ' . $input->siteName, '', $input->about, ''];
            foreach ($articles as $article) {
                $lines[] = '- [' . $article->title . '](' . $this->url($input, '/articles/' . $article->slug . '/') . ')';
            }
            $this->write($temporaryRoot . '/llms.txt', implode("\n", $lines) . "\n");
            $files[] = 'llms.txt';
        }
        return new BuildManifest(count($articles), $files);
    }

    /** @return array<string, mixed> */
    /** @param list<ArticleDocument> $articles */
    private function articleData(ArticleDocument $article, array $articles, BuildInput $input, string $route): array
    {
        $url = $this->url($input, $route);
        $date = (string) $article->frontMatter->get('date');
        $modified = (string) $article->frontMatter->get('updated', $date);
        $summary = (string) $article->frontMatter->get('summary', '');
        $sources = array_values(array_filter((array) $article->frontMatter->get('sources', []), 'is_string'));
        $structured = $article->frontMatter->get('structured_data');
        $graph = [
                ['@type' => 'Article', 'headline' => $article->title, 'datePublished' => $date, 'dateModified' => $modified, 'author' => ['@type' => 'Person', 'name' => $input->authorName], 'mainEntityOfPage' => $url, 'description' => $summary, 'citation' => $sources],
                ['@type' => 'Person', 'name' => $input->authorName],
                ['@type' => 'WebSite', 'name' => $input->siteName, 'url' => rtrim($input->siteUrl, '/') . '/'],
                ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $this->url($input, '/')], ['@type' => 'ListItem', 'position' => 2, 'name' => $article->title, 'item' => $url]]],
            ];
        if (is_array($structured)) $graph[] = $structured;
        $articleTopics = array_values(array_filter((array) $article->frontMatter->get('topics', []), 'is_string'));
        $related = array_values(array_filter($articles, static function (ArticleDocument $candidate) use ($article, $articleTopics): bool {
            if ($candidate->slug === $article->slug || $articleTopics === []) return false;
            return array_intersect($articleTopics, (array) $candidate->frontMatter->get('topics', [])) !== [];
        }));
        return [
            'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'siteLanguage' => $input->siteLanguage, 'article' => $article,
            'url' => $url, 'date' => $date, 'modified' => $modified, 'summary' => $summary, 'sources' => $sources, 'topics' => $articleTopics, 'related' => array_slice($related, 0, 3), 'contentHtml' => $this->markdown($article->bodyMarkdown),
            'jsonLd' => json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        ];
    }

    /** @param list<ArticleDocument> $articles */
    private function rss(array $articles, BuildInput $input): string
    {
        $items = array_map(function (ArticleDocument $article) use ($input): string {
            $url = $this->url($input, '/articles/' . $article->slug . '/');
            return '<item><title>' . $this->xml($article->title) . '</title><link>' . $this->xml($url) . '</link><guid>' . $this->xml($url) . '</guid><pubDate>' . $this->date((string) $article->frontMatter->get('date')) . '</pubDate><description>' . $this->xml((string) $article->frontMatter->get('summary', '')) . '</description></item>';
        }, $articles);
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . $this->xml($input->siteName) . '</title><link>' . $this->xml($input->siteUrl) . '</link><description>' . $this->xml($input->about) . '</description>' . implode('', $items) . '</channel></rss>';
    }

    /** @param list<ArticleDocument> $articles */
    private function jsonFeed(array $articles, BuildInput $input): string
    {
        $items = array_map(fn (ArticleDocument $article): array => ['id' => $this->url($input, '/articles/' . $article->slug . '/'), 'url' => $this->url($input, '/articles/' . $article->slug . '/'), 'title' => $article->title, 'date_published' => (string) $article->frontMatter->get('date'), 'summary' => (string) $article->frontMatter->get('summary', ''), 'content_html' => $this->markdown($article->bodyMarkdown), 'authors' => [['name' => $input->authorName]]], $articles);
        return json_encode(['version' => 'https://jsonfeed.org/version/1.1', 'title' => $input->siteName, 'home_page_url' => $input->siteUrl, 'feed_url' => $this->url($input, '/feed.json'), 'authors' => [['name' => $input->authorName]], 'items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param list<ArticleDocument> $articles */
    private function sitemap(array $articles, BuildInput $input, array $topicSlugs = []): string
    {
        $urls = [$this->url($input, '/'), $this->url($input, '/about/')];
        foreach ($articles as $article) {
            $urls[] = $this->url($input, '/articles/' . $article->slug . '/');
        }
        foreach ($topicSlugs as $topicSlug) $urls[] = $this->url($input, '/topics/' . $topicSlug . '/');
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', array_map(fn (string $url): string => '<url><loc>' . $this->xml($url) . '</loc></url>', $urls)) . '</urlset>';
    }

    private function markdown(string $markdown): string
    {
        $lines = preg_split('/\R/', trim($markdown)) ?: []; $html = []; $paragraph = []; $list = false; $quote = false; $code = false; $codeLines = [];
        $flush = function () use (&$paragraph, &$html): void { if ($paragraph !== []) { $html[] = '<p>' . implode("\n", $paragraph) . '</p>'; $paragraph = []; } };
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '```')) { $flush(); if ($code) { $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>'; $codeLines = []; } $code = !$code; continue; }
            if ($code) { $codeLines[] = $line; continue; }
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) { $flush(); if ($list) { $html[] = '</ul>'; $list = false; } if ($quote) { $html[] = '</blockquote>'; $quote = false; } $level = min(strlen($m[1]) + 1, 6); $html[] = '<h' . $level . '>' . $this->inline($m[2]) . '</h' . $level . '>'; continue; }
            if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) { $flush(); if ($quote) { $html[] = '</blockquote>'; $quote = false; } if (!$list) { $html[] = '<ul>'; $list = true; } $html[] = '<li>' . $this->inline($m[1]) . '</li>'; continue; }
            if ($list) { $html[] = '</ul>'; $list = false; }
            if (str_starts_with($line, '> ')) { $flush(); if ($list) { $html[] = '</ul>'; $list = false; } if (!$quote) { $html[] = '<blockquote>'; $quote = true; } $html[] = '<p>' . $this->inline(substr($line, 2)) . '</p>'; continue; }
            if ($quote) { $html[] = '</blockquote>'; $quote = false; }
            if ($line === '') { $flush(); continue; } $paragraph[] = $this->inline($line);
        }
        $flush(); if ($list) $html[] = '</ul>'; if ($quote) $html[] = '</blockquote>'; if ($code) $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>'; return implode("\n", $html);
    }

    private function inline(string $text): string { $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); return preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $escaped) ?? $escaped; }

    private function siteStyles(): string
    {
        $path = dirname(__DIR__, 2) . '/templates/public/site.css';
        $styles = file_get_contents($path);
        if ($styles === false) throw new RuntimeException('Unable to read the public site stylesheet.');
        return $styles;
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
    private function topicSlug(string $topic): string { return trim((string) preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($topic)) ?? ''), '-'); }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function date(string $date): string { return (new DateTimeImmutable($date))->format(DATE_RSS); }
}
