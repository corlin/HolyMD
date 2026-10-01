<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use HolyMD\Config\Env;
use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;
use Throwable;

final class DistributionService
{
    /**
     * @param (callable(string, array<string, mixed>, array<string, string>): bool)|null $transport Custom HTTP poster for testing
     */
    public function __construct(
        private mixed $transport = null,
    ) {
    }

    /**
     * Notify external distribution channels after successful publication.
     */
    public function distribute(ArticleDocument $article, PublicationSettings $settings): void
    {
        $this->notifyIndexNow($article, $settings);
        $this->notifyWebhook($article, $settings);
    }

    /**
     * Write IndexNow verification file to the public directory if key is configured.
     */
    public function writeVerificationFile(string $publicDirectory): void
    {
        $key = $this->indexNowKey();
        if ($key === null || $key === '') {
            return;
        }

        $filePath = rtrim($publicDirectory, '/') . '/' . $key . '.txt';
        @file_put_contents($filePath, $key);
    }

    private function notifyIndexNow(ArticleDocument $article, PublicationSettings $settings): void
    {
        $key = $this->indexNowKey();
        if ($key === null || $key === '') {
            return;
        }

        $host = (string) parse_url($settings->siteUrl, PHP_URL_HOST);
        if ($host === '') {
            return;
        }

        $articleUrl = rtrim($settings->siteUrl, '/') . '/articles/' . rawurlencode($article->slug) . '/';
        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => rtrim($settings->siteUrl, '/') . '/' . $key . '.txt',
            'urlList' => [$articleUrl],
        ];

        $endpoint = (string) (Env::get('HOLYMD_INDEXNOW_ENDPOINT') ?: 'https://api.indexnow.org/indexnow');
        $this->postJson($endpoint, $payload);
    }

    private function notifyWebhook(ArticleDocument $article, PublicationSettings $settings): void
    {
        $webhookUrl = Env::get('HOLYMD_PUBLISH_WEBHOOK_URL');
        if (!is_string($webhookUrl) || trim($webhookUrl) === '') {
            return;
        }

        $articleUrl = rtrim($settings->siteUrl, '/') . '/articles/' . rawurlencode($article->slug) . '/';
        $payload = [
            'event' => 'article.published',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'article' => [
                'slug' => $article->slug,
                'title' => $article->title,
                'subtitle' => $article->subtitle(),
                'url' => $articleUrl,
                'date' => (string) $article->frontMatter->get('date'),
                'status' => (string) $article->frontMatter->get('status', 'published'),
            ],
        ];

        $this->postJson(trim($webhookUrl), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $url, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        if (is_callable($this->transport)) {
            try {
                ($this->transport)($url, $payload, ['Content-Type' => 'application/json']);
            } catch (Throwable) {
                // Ignore test transport failures
            }
            return;
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: HolyMD/1.0\r\n",
                    'content' => $json,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            @file_get_contents($url, false, $context);
        } catch (Throwable $e) {
            error_log('HolyMD distribution notify failed: ' . $e->getMessage());
        }
    }

    private function indexNowKey(): ?string
    {
        $key = Env::get('HOLYMD_INDEXNOW_KEY');
        if (is_string($key) && preg_match('/^[a-zA-Z0-9_-]{8,128}$/', trim($key)) === 1) {
            return trim($key);
        }
        return null;
    }
}
