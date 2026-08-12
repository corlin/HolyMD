<?php

declare(strict_types=1);

namespace HolyMD\Deployment;

final readonly class PreflightReport
{
    /** @param list<string> $failures */
    public function __construct(public array $failures)
    {
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    public function text(): string
    {
        if ($this->passed()) {
            return "PASS: HolyMD is ready for this shared host.\n";
        }

        return "FAIL: HolyMD is not ready for deployment:\n- " . implode("\n- ", $this->failures) . "\n";
    }
}
