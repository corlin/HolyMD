<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use HolyMD\Config\Env;
use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Publish\DistributionService;
use PHPUnit\Framework\TestCase;

final class DistributionServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/holymd-dist-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
        Env::set('HOLYMD_INDEXNOW_KEY', null);
        Env::set('HOLYMD_PUBLISH_WEBHOOK_URL', null);
    }

    public function test_write_verification_file_when_indexnow_configured(): void
    {
        Env::set('HOLYMD_INDEXNOW_KEY', 'test-indexnow-key-12345');

        $service = new DistributionService();
        $service->writeVerificationFile($this->tempDir);

        $expectedFile = $this->tempDir . '/test-indexnow-key-12345.txt';
        self::assertFileExists($expectedFile);
        self::assertSame('test-indexnow-key-12345', file_get_contents($expectedFile));
    }

    public function test_distribute_calls_indexnow_and_webhook_transports(): void
    {
        Env::set('HOLYMD_INDEXNOW_KEY', 'test-key-abcdef123');
        Env::set('HOLYMD_PUBLISH_WEBHOOK_URL', 'https://webhook.example.test/publish');

        $calls = [];
        $transport = function (string $url, array $payload) use (&$calls): bool {
            $calls[] = ['url' => $url, 'payload' => $payload];
            return true;
        };

        $service = new DistributionService($transport);
        $settings = new PublicationSettings('Test Site', 'https://example.test', 'Author', 'Bio');

        $doc = new ArticleDocument(
            'hello-world',
            'Hello World',
            'Body',
            new FrontMatter(['title' => 'Hello World', 'slug' => 'hello-world', 'date' => '2026-10-01', 'status' => 'published']),
            'hello-world.md'
        );

        $service->distribute($doc, $settings);

        self::assertCount(2, $calls);

        // Call 1: IndexNow
        self::assertSame('https://api.indexnow.org/indexnow', $calls[0]['url']);
        self::assertSame('example.test', $calls[0]['payload']['host']);
        self::assertSame('test-key-abcdef123', $calls[0]['payload']['key']);
        self::assertSame(['https://example.test/articles/hello-world/'], $calls[0]['payload']['urlList']);

        // Call 2: Webhook
        self::assertSame('https://webhook.example.test/publish', $calls[1]['url']);
        self::assertSame('article.published', $calls[1]['payload']['event']);
        self::assertSame('hello-world', $calls[1]['payload']['article']['slug']);
        self::assertSame('Hello World', $calls[1]['payload']['article']['title']);
    }

    public function test_distribute_noop_when_not_configured(): void
    {
        $calls = [];
        $transport = function (string $url, array $payload) use (&$calls): bool {
            $calls[] = $url;
            return true;
        };

        $service = new DistributionService($transport);
        $settings = new PublicationSettings('Test Site', 'https://example.test', 'Author', 'Bio');
        $doc = new ArticleDocument(
            'hello-world',
            'Hello World',
            'Body',
            new FrontMatter(['title' => 'Hello World', 'slug' => 'hello-world', 'date' => '2026-10-01', 'status' => 'published']),
            'hello-world.md'
        );

        $service->distribute($doc, $settings);
        self::assertSame([], $calls);
    }
}
