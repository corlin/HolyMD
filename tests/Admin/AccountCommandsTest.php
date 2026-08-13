<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\AccountCommands;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccountCommandsTest extends TestCase
{
    private PDO $pdo;
    private AccountCommands $commands;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->commands = new AccountCommands($this->pdo);
    }

    public function test_create_adds_a_lowercased_admin_with_a_verifiable_hash(): void
    {
        $this->commands->create('ADMIN@Example.Test', 'Admin', str_repeat('p', 12));

        $row = $this->pdo->query('SELECT email, display_name, password_hash FROM admin_users')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('admin@example.test', $row['email']);
        self::assertSame('Admin', $row['display_name']);
        self::assertTrue(password_verify(str_repeat('p', 12), $row['password_hash']));
    }

    public function test_create_rejects_an_invalid_email_and_a_short_password(): void
    {
        try {
            $this->commands->create('not-an-email', 'Admin', str_repeat('p', 12));
            self::fail('Invalid email must be rejected.');
        } catch (InvalidArgumentException) {
        }

        try {
            $this->commands->create('admin@example.test', 'Admin', 'short');
            self::fail('Short password must be rejected.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn());
    }

    public function test_list_returns_accounts_with_lock_state(): void
    {
        $this->commands->create('one@example.test', 'One', str_repeat('p', 12));
        $this->pdo->exec("UPDATE admin_users SET failed_attempts = 3, locked_until = '2030-01-01 00:00:00.000000' WHERE email = 'one@example.test'");

        $accounts = $this->commands->list();

        self::assertCount(1, $accounts);
        self::assertSame('one@example.test', $accounts[0]['email']);
        self::assertSame(3, (int) $accounts[0]['failed_attempts']);
        self::assertSame('2030-01-01 00:00:00.000000', $accounts[0]['locked_until']);
        self::assertSame(1, (int) $accounts[0]['is_active']);
    }

    public function test_password_reset_clears_the_lock_state(): void
    {
        $this->commands->create('one@example.test', 'One', str_repeat('p', 12));
        $this->pdo->exec("UPDATE admin_users SET failed_attempts = 5, locked_until = '2030-01-01 00:00:00.000000'");

        $this->commands->passwordReset('one@example.test', str_repeat('q', 12));

        $row = $this->pdo->query('SELECT failed_attempts, locked_until, password_hash FROM admin_users')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['failed_attempts']);
        self::assertNull($row['locked_until']);
        self::assertTrue(password_verify(str_repeat('q', 12), $row['password_hash']));
    }

    public function test_disable_refuses_the_last_active_admin(): void
    {
        $this->commands->create('one@example.test', 'One', str_repeat('p', 12));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last active administrator');

        $this->commands->disable('one@example.test');
    }

    public function test_disable_and_enable_round_trip(): void
    {
        $this->commands->create('one@example.test', 'One', str_repeat('p', 12));
        $this->commands->create('two@example.test', 'Two', str_repeat('p', 12));
        $this->pdo->exec("UPDATE admin_users SET failed_attempts = 2 WHERE email = 'one@example.test'");

        $this->commands->disable('one@example.test');
        self::assertSame(0, (int) $this->pdo->query("SELECT is_active FROM admin_users WHERE email = 'one@example.test'")->fetchColumn());

        $this->commands->enable('one@example.test');
        $row = $this->pdo->query("SELECT is_active, failed_attempts FROM admin_users WHERE email = 'one@example.test'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame(0, (int) $row['failed_attempts']);
    }

    public function test_unlock_resets_failed_attempts_and_lock(): void
    {
        $this->commands->create('one@example.test', 'One', str_repeat('p', 12));
        $this->pdo->exec("UPDATE admin_users SET failed_attempts = 5, locked_until = '2030-01-01 00:00:00.000000'");

        $this->commands->unlock('one@example.test');

        $row = $this->pdo->query('SELECT failed_attempts, locked_until FROM admin_users')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['failed_attempts']);
        self::assertNull($row['locked_until']);
    }

    public function test_unknown_email_raises(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->commands->unlock('nobody@example.test');
    }
}
