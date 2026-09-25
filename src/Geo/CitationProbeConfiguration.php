<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Config\Env;

/**
 * Settings for citation probes: an OpenAI-compatible chat endpoint backed by
 * web search that returns the sources it cited (Perplexity Sonar by default).
 */
final readonly class CitationProbeConfiguration
{
    public const CREDENTIAL_VARIABLE = 'HOLYMD_PROBE_API_CREDENTIAL';
    public const KEY_VARIABLE = 'HOLYMD_PROBE_API_KEY';

    public function __construct(
        public string $endpoint,
        public string $model,
        public bool $configured,
        public int $timeoutSeconds = 60,
        public int $questionsPerArticle = 2,
        public int $maxPerRun = 10,
        public int $maxResponseBytes = 524288,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string) (Env::get('HOLYMD_PROBE_API_ENDPOINT') ?: 'https://api.perplexity.ai/chat/completions'),
            (string) (Env::get('HOLYMD_PROBE_MODEL') ?: 'sonar'),
            (bool) (Env::get(self::CREDENTIAL_VARIABLE) && Env::get(self::KEY_VARIABLE)),
            max(1, min(120, (int) (Env::get('HOLYMD_PROBE_TIMEOUT_SECONDS') ?: 60))),
            max(1, min(5, (int) (Env::get('HOLYMD_PROBE_QUESTIONS_PER_ARTICLE') ?: 2))),
            max(1, min(100, (int) (Env::get('HOLYMD_PROBE_MAX_PER_RUN') ?: 10))),
        );
    }
}
