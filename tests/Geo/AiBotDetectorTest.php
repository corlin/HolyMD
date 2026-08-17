<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Geo\AiBotDetector;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiBotDetectorTest extends TestCase
{
    public function test_detects_common_ai_bots_by_user_agent(): void
    {
        self::assertSame('GPTBot', AiBotDetector::detect('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'));
        self::assertSame('GPTBot', AiBotDetector::detect('ChatGPT-User/1.0'));
        self::assertSame('GPTBot', AiBotDetector::detect('OAI-SearchBot/1.0'));
        self::assertSame('ClaudeBot', AiBotDetector::detect('ClaudeBot/1.0; +claudebot@anthropic.com'));
        self::assertSame('ClaudeBot', AiBotDetector::detect('anthropic-ai/1.0'));
        self::assertSame('PerplexityBot', AiBotDetector::detect('Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://docs.perplexity.ai/docs/perplexitybot)'));
        self::assertSame('Google-AI', AiBotDetector::detect('Mozilla/5.0 (compatible; Google-Extended/1.0; +https://developers.google.com/search/docs/crawling-indexing/google-extended)'));
        self::assertSame('Bytespider', AiBotDetector::detect('Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)'));
        self::assertSame('Cohere-AI', AiBotDetector::detect('cohere-ai/1.0'));
        self::assertSame('Applebot-AI', AiBotDetector::detect('Applebot-Extended/1.0'));
        self::assertSame('Meta-AI', AiBotDetector::detect('Meta-ExternalAgent/1.0'));
        self::assertSame('DeepSeek', AiBotDetector::detect('deepseek-ai/1.0'));
    }

    public function test_returns_null_for_standard_human_browsers(): void
    {
        self::assertNull(AiBotDetector::detect(null));
        self::assertNull(AiBotDetector::detect(''));
        self::assertNull(AiBotDetector::detect('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));
        self::assertNull(AiBotDetector::detect('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
    }

    public function test_hashes_ip_consistently_and_anonymously(): void
    {
        $hash1 = AiBotDetector::hashIp('192.168.1.100');
        $hash2 = AiBotDetector::hashIp('192.168.1.100');
        $hash3 = AiBotDetector::hashIp('10.0.0.1');

        self::assertSame($hash1, $hash2);
        self::assertNotSame($hash1, $hash3);
        self::assertSame(16, strlen($hash1));
    }

    public function test_records_visit_to_database(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE ai_bot_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bot_name TEXT NOT NULL,
            request_path TEXT NOT NULL,
            http_status INTEGER NOT NULL,
            ip_hash TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        AiBotDetector::recordVisit($pdo, 'GPTBot', '/llms.txt', 200, '127.0.0.1', 'GPTBot/1.0');

        $row = $pdo->query('SELECT * FROM ai_bot_visits')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('GPTBot', $row['bot_name']);
        self::assertSame('/llms.txt', $row['request_path']);
        self::assertSame(200, (int) $row['http_status']);
    }
}
