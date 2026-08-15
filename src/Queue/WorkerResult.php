<?php

declare(strict_types=1);

namespace HolyMD\Queue;

final readonly class WorkerResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {
    }
}
