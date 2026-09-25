<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Geo\AiReferralDetector;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiReferralDetectorTest extends TestCase
{
    public function test_recognizes_ai_referrers_by_host_and_parent_domain(): void
    {
        self::assertSame(['source' => 'ChatGPT', 'referrer_host' => 'chatgpt.com'], AiReferralDetector::detect('https://chatgpt.com/', null));
        self::assertSame(['source' => 'Perplexity', 'referrer_host' => 'www.perplexity.ai'], AiReferralDetector::detect('https://www.perplexity.ai/search?q=geo', null));
        self::assertSame('Gemini', AiReferralDetector::detect('https://gemini.google.com/app', null)['source'] ?? null);
        self::assertSame('Kimi', AiReferralDetector::detect('https://kimi.moonshot.cn/chat/abc', null)['source'] ?? null);
        self::assertSame('Doubao', AiReferralDetector::detect('https://www.doubao.com/chat/', null)['source'] ?? null);
        self::assertSame('DeepSeek', AiReferralDetector::detect('https://chat.deepseek.com/', null)['source'] ?? null);
    }

    public function test_recognizes_utm_source_tags_when_the_referrer_is_missing(): void
    {
        self::assertSame(['source' => 'ChatGPT', 'referrer_host' => null], AiReferralDetector::detect(null, 'chatgpt.com'));
        self::assertSame('Perplexity', AiReferralDetector::detect('', 'Perplexity')['source'] ?? null);
        self::assertSame('Copilot', AiReferralDetector::detect('https://www.google.com/', 'copilot')['source'] ?? null);
    }

    public function test_ignores_ordinary_search_engines_and_lookalike_hosts(): void
    {
        self::assertNull(AiReferralDetector::detect('https://www.google.com/search?q=geo', null));
        self::assertNull(AiReferralDetector::detect('https://notchatgpt.com/', null));
        self::assertNull(AiReferralDetector::detect('https://chatgpt.com.example.net/', null));
        self::assertNull(AiReferralDetector::detect('not a url', 'newsletter'));
        self::assertNull(AiReferralDetector::detect(null, null));
    }

    public function test_records_source_path_status_and_host_without_visitor_identifiers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE ai_referrals (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, landing_path TEXT NOT NULL, http_status INTEGER NOT NULL, referrer_host TEXT NULL, created_at TEXT NOT NULL)');

        AiReferralDetector::recordVisit($pdo, 'ChatGPT', '/articles/what-is-geo/', 200, 'chatgpt.com');
        AiReferralDetector::recordVisit($pdo, 'Perplexity', '/articles/gone/', 404, null);

        $rows = $pdo->query('SELECT source, landing_path, http_status, referrer_host FROM ai_referrals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            ['source' => 'ChatGPT', 'landing_path' => '/articles/what-is-geo/', 'http_status' => 200, 'referrer_host' => 'chatgpt.com'],
            ['source' => 'Perplexity', 'landing_path' => '/articles/gone/', 'http_status' => 404, 'referrer_host' => null],
        ], $rows);
    }
}
