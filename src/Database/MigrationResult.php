<?php

declare(strict_types=1);

namespace HolyMD\Database;

final readonly class MigrationResult
{
    public function __construct(public bool $installed, public int $applied)
    {
    }
}
