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
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
            throw new RuntimeException('Unable to create temporary build directory.');
        }
        $articles = $input->articles;
        usort($articles, static fn (ArticleDocument $a, ArticleDocument $b): int => strcmp((string) $b->frontMatter->get('date'), (string) $a->frontMatter->get('date')));
        $files = [];
        foreach ($articles as $article) {
            $route = '/articles/' . $article->slug . '/';
            $path = $temporaryRoot . $route . 'index.html';
            $this->write($path, $this->renderer->render('article', $this->articleData($article, $input, $route)));
            $files[] = $route . 'index.html';
        }
        $topics = [];
        foreach ($articles as $article) {
            foreach ((array) $article->frontMatter->get('topics', []) as $topic) {
                $topics[(string) $topic][] = $article;
            }
        }
        foreach ($topics as $topic => $topicArticles) {
            $slug = $this->topicSlug($topic);
            $route = '/topics/' . $slug . '/';
            $this->write($temporaryRoot . $route . 'index.html', $this->renderer->render('topic', ['siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'topic' => $topic, 'articles' => $topicArticles, 'route' => $route]));
            $files[] = $route . 'index.html';
        }
        $this->write($temporaryRoot . '/index.html', $this->renderer->render('index', ['siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'articles' => $articles]));
        $this->write($temporaryRoot . '/about/index.html', $this->renderer->render('about', ['siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'about' => $input->about]));
        $this->write($temporaryRoot . '/rss.xml', $this->rss($articles, $input));
        $this->write($temporaryRoot . '/feed.json', $this->jsonFeed($articles, $input));
        $this->write($temporaryRoot . '/sitemap.xml', $this->sitemap($articles, $input));
        $this->write($temporaryRoot . '/robots.txt', "User-agent: *\nAllow: /\nSitemap: " . $this->url($input, '/sitemap.xml') . "\n");
        $files = [...$files, 'index.html', 'about/index.html', 'rss.xml', 'feed.json', 'sitemap.xml', 'robots.txt'];
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
    private function articleData(ArticleDocument $article, BuildInput $input, string $route): array
    {
        $url = $this->url($input, $route);
        $date = (string) $article->frontMatter->get('date');
        $modified = (string) $article->frontMatter->get('updated', $date);
        $summary = (string) $article->frontMatter->get('summary', '');
        return [
            'siteName' => $input->siteName, 'siteUrl' => $input->siteUrl, 'authorName' => $input->authorName, 'article' => $article,
            'url' => $url, 'date' => $date, 'modified' => $modified, 'summary' => $summary, 'contentHtml' => $this->markdown($article->bodyMarkdown),
            'jsonLd' => json_encode(['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'Article', 'headline' => $article->title, 'datePublished' => $date, 'dateModified' => $modified, 'author' => ['@type' => 'Person', 'name' => $input->authorName], 'mainEntityOfPage' => $url, 'description' => $summary],
                ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $this->url($input, '/')], ['@type' => 'ListItem', 'position' => 2, 'name' => $article->title, 'item' => $url]]],
            ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
    private function sitemap(array $articles, BuildInput $input): string
    {
        $urls = [$this->url($input, '/'), $this->url($input, '/about/')];
        foreach ($articles as $article) {
            $urls[] = $this->url($input, '/articles/' . $article->slug . '/');
        }
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', array_map(fn (string $url): string => '<url><loc>' . $this->xml($url) . '</loc></url>', $urls)) . '</urlset>';
    }

    private function markdown(string $markdown): string
    {
        $blocks = preg_split('/\n{2,}/', trim($markdown)) ?: [];
        $html = [];
        foreach ($blocks as $block) {
            if (preg_match('/^# (.+)$/', $block, $matches) === 1) {
                $html[] = '<h1>' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
            } else {
                $html[] = '<p>' . nl2br(htmlspecialchars($block, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
        }
        return implode("\n", $html);
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
