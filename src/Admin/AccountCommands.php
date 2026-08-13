<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class AccountCommands
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $email, string $displayName, string $password): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        if (trim($displayName) === '') {
            throw new InvalidArgumentException('A display name is required.');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('The administrator password must be at least 12 characters.');
        }
        try {
            $this->pdo->prepare('INSERT INTO admin_users (email, password_hash, display_name) VALUES (?, ?, ?)')->execute([mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), trim($displayName)]);
        } catch (PDOException) {
            throw new RuntimeException('Administrator was not created (the email may already exist).');
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $statement = $this->pdo->query('SELECT id, email, display_name, is_active, failed_attempts, locked_until, created_at FROM admin_users ORDER BY id');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function passwordReset(string $email, string $newPassword): void
    {
        if (strlen($newPassword) < 12) {
            throw new InvalidArgumentException('The administrator password must be at least 12 characters.');
        }
        $id = $this->find($email);
        $this->pdo->prepare('UPDATE admin_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
    }

    public function disable(string $email): void
    {
        $id = $this->find($email);
        $statement = $this->pdo->prepare('SELECT is_active FROM admin_users WHERE id = ?');
        $statement->execute([$id]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('The administrator is already disabled.');
        }
        $activeCount = $this->pdo->query('SELECT COUNT(*) FROM admin_users WHERE is_active = 1')->fetchColumn();
        if ((int) $activeCount <= 1) {
            throw new RuntimeException('The last active administrator cannot be disabled.');
        }
        $this->pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE id = ?')->execute([$id]);
    }

    public function enable(string $email): void
    {
        $id = $this->find($email);
        $this->pdo->prepare('UPDATE admin_users SET is_active = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
    }

    public function unlock(string $email): void
    {
        $id = $this->find($email);
        $this->pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
    }

    private function find(string $email): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Administrator not found.');
        }
        return (int) $id;
    }
}
