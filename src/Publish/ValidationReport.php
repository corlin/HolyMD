<?php

declare(strict_types=1);

namespace HolyMD\Publish;

final readonly class ValidationReport
{
    /** @param list<string> $errors */
    public function __construct(public array $errors = [])
    {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function text(): string
    {
        return $this->isValid() ? 'PASS: publish validation succeeded.' : 'FAIL: ' . implode("\n- ", $this->errors);
    }
}
