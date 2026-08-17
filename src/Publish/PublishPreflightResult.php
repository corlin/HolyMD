<?php

declare(strict_types=1);

namespace HolyMD\Publish;

final readonly class PublishPreflightResult
{
    /**
     * @param list<string> $changes
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    public function __construct(
        public string $checksum,
        public ?int $currentScore,
        public int $candidateScore,
        public array $changes,
        public array $blockers,
        public array $warnings,
    ) {
    }

    public function canPublish(): bool
    {
        return $this->blockers === [];
    }

    public function requiresAcknowledgement(): bool
    {
        return $this->warnings !== [];
    }
}
