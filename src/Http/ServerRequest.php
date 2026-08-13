<?php

declare(strict_types=1);

namespace HolyMD\Http;

final readonly class ServerRequest
{
    /** @param array<string, string> $headers @param array<string, mixed> $parsedBody @param array<string, mixed> $files */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $parsedBody = [],
        public array $files = [],
    ) {
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->parsedBody[$name] ?? $default;
    }

    public function stringInput(string $name): ?string
    {
        $value = $this->parsedBody[$name] ?? null;
        return is_string($value) ? $value : null;
    }
}
