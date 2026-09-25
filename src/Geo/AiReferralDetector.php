<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use PDO;
use Throwable;

/**
 * Recognizes human visitors who arrive from an AI assistant or AI search
 * engine, using the Referer header or a utm_source tag. Only the source, the
 * landing path, and the referring host are stored: no IP address or user agent.
 *
 * Many AI apps send no referrer at all, so recorded referrals are a lower bound.
 */
final class AiReferralDetector
{
    /** @var array<string, string> Referrer host (matched exactly or as a parent domain) => source name */
    private const HOSTS = [
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
        'gemini.google.com' => 'Gemini',
        'bard.google.com' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot',
        'claude.ai' => 'Claude',
        'chat.deepseek.com' => 'DeepSeek',
        'kimi.com' => 'Kimi',
        'kimi.moonshot.cn' => 'Kimi',
        'doubao.com' => 'Doubao',
        'yuanbao.tencent.com' => 'Yuanbao',
        'tongyi.com' => 'Qwen',
        'tongyi.aliyun.com' => 'Qwen',
        'qianwen.aliyun.com' => 'Qwen',
        'yiyan.baidu.com' => 'ERNIE Bot',
        'metaso.cn' => 'Metaso',
        'you.com' => 'You.com',
        'phind.com' => 'Phind',
        'poe.com' => 'Poe',
        'grok.com' => 'Grok',
        'meta.ai' => 'Meta AI',
        'chat.mistral.ai' => 'Mistral',
    ];

    /** @var array<string, string> Bare utm_source values some assistants use => source name */
    private const UTM_ALIASES = [
        'chatgpt' => 'ChatGPT',
        'openai' => 'ChatGPT',
        'perplexity' => 'Perplexity',
        'gemini' => 'Gemini',
        'copilot' => 'Copilot',
        'claude' => 'Claude',
        'deepseek' => 'DeepSeek',
        'kimi' => 'Kimi',
        'doubao' => 'Doubao',
        'grok' => 'Grok',
    ];

    /** @return array{source: string, referrer_host: ?string}|null */
    public static function detect(?string $referer, ?string $utmSource): ?array
    {
        $host = is_string($referer) && $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;
        $host = is_string($host) ? strtolower($host) : null;
        if ($host !== null) {
            $source = self::sourceForHost($host);
            if ($source !== null) {
                return ['source' => $source, 'referrer_host' => $host];
            }
        }

        $utm = is_string($utmSource) ? strtolower(trim($utmSource)) : '';
        if ($utm !== '') {
            $source = self::sourceForHost($utm) ?? self::UTM_ALIASES[$utm] ?? null;
            if ($source !== null) {
                return ['source' => $source, 'referrer_host' => $host];
            }
        }

        return null;
    }

    public static function recordVisit(PDO $pdo, string $source, string $path, int $httpStatus, ?string $referrerHost): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO ai_referrals (source, landing_path, http_status, referrer_host, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            substr($source, 0, 64),
            substr($path, 0, 768),
            $httpStatus,
            $referrerHost === null ? null : substr($referrerHost, 0, 255),
            gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record the current public page request when it came from an AI source.
     * Crawlers are tracked separately and never counted as referrals.
     */
    public static function trackIfReferred(?string $root, string $path, int $httpStatus): void
    {
        if (AiBotDetector::detect($_SERVER['HTTP_USER_AGENT'] ?? null) !== null) {
            return;
        }
        $utmSource = $_GET['utm_source'] ?? null;
        $referral = self::detect(
            is_string($_SERVER['HTTP_REFERER'] ?? null) ? $_SERVER['HTTP_REFERER'] : null,
            is_string($utmSource) ? $utmSource : null,
        );
        if ($referral === null) {
            return;
        }

        try {
            $pdo = (new \HolyMD\Database\Connection(\HolyMD\Config\Settings::fromEnvironment($root)))->pdo();
            self::recordVisit($pdo, $referral['source'], $path, $httpStatus, $referral['referrer_host']);
        } catch (Throwable) {
            // Never interrupt public file serving because analytics failed.
        }
    }

    private static function sourceForHost(string $host): ?string
    {
        foreach (self::HOSTS as $known => $source) {
            if ($host === $known || str_ends_with($host, '.' . $known)) {
                return $source;
            }
        }
        return null;
    }
}
