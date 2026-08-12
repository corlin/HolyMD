<?php

declare(strict_types=1);

namespace HolyMD\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public string $body = '', public array $headers = [])
    {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location): self
    {
        return new self(303, '', ['Location' => $location]);
    }
}
