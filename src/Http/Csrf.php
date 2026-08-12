<?php

declare(strict_types=1);

namespace HolyMD\Http;

final readonly class Csrf
{
    /** @param array<string, mixed> $session */
    public function __construct(private array $session)
    {
    }

    public function valid(ServerRequest $request): bool
    {
        $expected = $this->session['csrf_token'] ?? null;
        $provided = $request->input('csrf_token', $request->headers['X-CSRF-Token'] ?? null);
        return is_string($expected) && is_string($provided) && hash_equals($expected, $provided);
    }

    public function token(): string
    {
        return is_string($this->session['csrf_token'] ?? null) ? $this->session['csrf_token'] : '';
    }
}
