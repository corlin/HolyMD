<?php

declare(strict_types=1);

namespace HolyMD\Auth;

final class Unauthorized extends \RuntimeException {}

final readonly class AdminGuard
{
    /** @param array<string, mixed> $session */
    public function __construct(private array $session)
    {
    }

    public function requireAdministrator(): int
    {
        $userId = $this->session['admin_user_id'] ?? null;
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            throw new Unauthorized('Administrator authentication is required.');
        }
        return (int) $userId;
    }
}
