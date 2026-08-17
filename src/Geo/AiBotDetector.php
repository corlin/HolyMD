<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use PDO;
use Throwable;

final class AiBotDetector
{
    /** @var array<string, string> Regex pattern => Standard Bot Name */
    private const BOT_PATTERNS = [
        // OpenAI
        '/\b(GPTBot|ChatGPT-User|OAI-SearchBot)\b/i' => 'GPTBot',
        // Anthropic
        '/\b(ClaudeBot|Claude-Web|anthropic-ai)\b/i' => 'ClaudeBot',
        // Perplexity
        '/\b(PerplexityBot)\b/i' => 'PerplexityBot',
        // Google Extended & AI
        '/\b(Google-Extended|GoogleOther)\b/i' => 'Google-AI',
        // ByteDance / Doubao
        '/\b(Bytespider)\b/i' => 'Bytespider',
        // Cohere
        '/\b(cohere-ai)\b/i' => 'Cohere-AI',
        // Apple
        '/\b(Applebot-Extended)\b/i' => 'Applebot-AI',
        // Meta
        '/\b(Meta-ExternalAgent|FacebookBot)\b/i' => 'Meta-AI',
        // Amazon
        '/\b(Amazonbot)\b/i' => 'Amazonbot',
        // DeepSeek
        '/\b(deepseek-ai|DeepSeek)\b/i' => 'DeepSeek',
    ];

    public static function detect(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        foreach (self::BOT_PATTERNS as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }

    public static function hashIp(string $ip): string
    {
        return substr(hash('sha256', $ip . ':holymd_bot_salt'), 0, 16);
    }

    public static function recordVisit(
        ?PDO $pdo,
        string $botName,
        string $path,
        int $httpStatus,
        string $ip,
        string $userAgent
    ): void {
        if ($pdo === null) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ai_bot_visits (bot_name, request_path, http_status, ip_hash, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                substr($botName, 0, 64),
                substr($path, 0, 768),
                $httpStatus,
                self::hashIp($ip),
                substr($userAgent, 0, 512),
                gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Silently ignore logging errors so public file serving is never interrupted
        }
    }
}
