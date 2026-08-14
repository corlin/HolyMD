<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\ProfileController;
use HolyMD\Auth\AdminGuard;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfileControllerTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->prepare('INSERT INTO admin_users (id, email, password_hash, display_name) VALUES (?, ?, ?, ?)')->execute([9, 'admin@example.test', password_hash('correct horse', PASSWORD_DEFAULT), 'Admin']);
        $this->pdo->prepare('INSERT INTO admin_users (id, email, password_hash, display_name) VALUES (?, ?, ?, ?)')->execute([10, 'other@example.test', password_hash('other horse battery', PASSWORD_DEFAULT), 'Other']);
    }

    public function test_profile_page_requires_authentication(): void
    {
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/profile'));
        self::assertSame(401, $response->status);
    }

    public function test_profile_page_displays_current_admin_info(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'test-csrf'];
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/profile'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('admin@example.test', $response->body);
        self::assertStringContainsString('Admin', $response->body);
    }

    public function test_update_name_and_email(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'test-csrf'];
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/profile', [], [
            'csrf_token' => 'test-csrf',
            'display_name' => 'New Name',
            'email' => 'newadmin@example.test',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Profile updated successfully.', $response->body);

        $stmt = $this->pdo->query('SELECT email, display_name FROM admin_users WHERE id = 9');
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('newadmin@example.test', $user['email']);
        self::assertSame('New Name', $user['display_name']);
    }

    public function test_update_password_verifies_current_password(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'test-csrf'];

        // Wrong current password
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/profile', [], [
            'csrf_token' => 'test-csrf',
            'display_name' => 'Admin',
            'email' => 'admin@example.test',
            'current_password' => 'wrong password',
            'new_password' => 'new password 123',
            'confirm_password' => 'new password 123',
        ]));
        self::assertStringContainsString('Current password is incorrect.', $response->body);

        // Matching current password and new password
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/profile', [], [
            'csrf_token' => 'test-csrf',
            'display_name' => 'Admin',
            'email' => 'admin@example.test',
            'current_password' => 'correct horse',
            'new_password' => 'new password 123',
            'confirm_password' => 'new password 123',
        ]));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Profile updated successfully.', $response->body);

        $hash = $this->pdo->query('SELECT password_hash FROM admin_users WHERE id = 9')->fetchColumn();
        self::assertTrue(password_verify('new password 123', (string) $hash));
    }

    public function test_rejects_duplicate_email(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'test-csrf'];
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/profile', [], [
            'csrf_token' => 'test-csrf',
            'display_name' => 'Admin',
            'email' => 'other@example.test',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('already in use', $response->body);
    }

    private function router(): Router
    {
        $guard = new AdminGuard($this->session);
        $csrf = new Csrf($this->session);
        $profile = new ProfileController($this->pdo, $guard, $csrf);
        return new Router(profile: $profile);
    }
}
