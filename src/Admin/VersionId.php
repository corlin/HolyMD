<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use InvalidArgumentException;

final readonly class VersionId
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new InvalidArgumentException('Version ID must be a 32-character hexadecimal value.');
        }
    }
}
