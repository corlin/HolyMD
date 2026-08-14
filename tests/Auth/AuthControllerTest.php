<?php

declare(strict_types=1);

namespace HolyMD\Tests\Auth;

use HolyMD\Auth\AuthController;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->prepare('INSERT INTO admin_users (id, email, password_hash, display_name) VALUES (?, ?, ?, ?)')->execute([9, 'admin@example.test', password_hash('correct horse', PASSWORD_DEFAULT), 'Admin']);
    }

    public function test_valid_credentials_create_a_session_and_redirect_to_articles(): void
    {
        $this->session['csrf_token'] = 'login-token';
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'ADMIN@example.test', 'password' => 'correct horse', 'csrf_token' => 'login-token']));

        self::assertSame(303, $response->status);
        self::assertSame('/admin/articles', $response->headers['Location']);
        self::assertSame(9, $this->session['admin_user_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->session['csrf_token']);
    }

    public function test_login_with_remember_me_sets_session_flag_and_logout_clears_it(): void
    {
        $this->session['csrf_token'] = 'login-token';
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'correct horse', 'remember_me' => '1', 'csrf_token' => 'login-token']));

        self::assertSame(303, $response->status);
        self::assertTrue($this->session['remember_me'] ?? false);

        $logoutResponse = $this->router()->dispatch(new ServerRequest('POST', '/admin/logout', [], ['csrf_token' => $this->session['csrf_token']]));
        self::assertSame(303, $logoutResponse->status);
        self::assertArrayNotHasKey('remember_me', $this->session);
    }

    public function test_invalid_credentials_do_not_create_a_session(): void
    {
        $this->session['csrf_token'] = 'login-token';
        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'wrong', 'csrf_token' => 'login-token']));

        self::assertSame(422, $response->status);
        self::assertArrayNotHasKey('admin_user_id', $this->session);
    }

    public function test_logout_requires_csrf_and_clears_the_administrator_session(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'expected'];
        $router = $this->router();
        self::assertSame(419, $router->dispatch(new ServerRequest('POST', '/admin/logout'))->status);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/logout', [], ['csrf_token' => 'expected']));
        self::assertSame(303, $response->status);
        self::assertSame('/admin/login', $response->headers['Location']);
        self::assertArrayNotHasKey('admin_user_id', $this->session);
        self::assertArrayNotHasKey('csrf_token', $this->session);
    }

    public function test_login_form_creates_a_csrf_token_and_rejects_a_missing_one(): void
    {
        self::assertSame(200, $this->router()->dispatch(new ServerRequest('GET', '/admin/login'))->status);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->session['csrf_token']);
        self::assertSame(419, $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'correct horse']))->status);
    }

    public function test_five_failed_attempts_lock_the_account_even_with_the_correct_password(): void
    {
        $this->session['csrf_token'] = 'login-token';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            self::assertSame(422, $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'wrong', 'csrf_token' => 'login-token']))->status);
        }

        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'correct horse', 'csrf_token' => 'login-token']));

        self::assertSame(429, $response->status);
        self::assertStringContainsString('temporarily locked', $response->body);
        self::assertArrayNotHasKey('admin_user_id', $this->session);
        $statement = $this->pdo->query('SELECT failed_attempts, locked_until FROM admin_users WHERE id = 9');
        $row = $statement->fetch();
        self::assertSame(5, (int) $row['failed_attempts']);
        self::assertNotNull($row['locked_until']);
    }

    public function test_an_expired_lock_allows_login_and_resets_the_counters(): void
    {
        $this->pdo->exec('UPDATE admin_users SET failed_attempts = 5, locked_until = ' . $this->pdo->quote(gmdate('Y-m-d H:i:s.u', time() - 60)) . ' WHERE id = 9');
        $this->session['csrf_token'] = 'login-token';

        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'correct horse', 'csrf_token' => 'login-token']));

        self::assertSame(303, $response->status);
        self::assertSame(9, $this->session['admin_user_id']);
        $statement = $this->pdo->query('SELECT failed_attempts, locked_until FROM admin_users WHERE id = 9');
        $row = $statement->fetch();
        self::assertSame(0, (int) $row['failed_attempts']);
        self::assertNull($row['locked_until']);
    }

    public function test_a_disabled_account_is_rejected_without_counting_failures(): void
    {
        $this->pdo->exec('UPDATE admin_users SET is_active = 0 WHERE id = 9');
        $this->session['csrf_token'] = 'login-token';

        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'correct horse', 'csrf_token' => 'login-token']));

        self::assertSame(422, $response->status);
        self::assertArrayNotHasKey('admin_user_id', $this->session);
        $statement = $this->pdo->query('SELECT failed_attempts FROM admin_users WHERE id = 9');
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function test_successful_login_resets_previous_failures(): void
    {
        $this->pdo->exec('UPDATE admin_users SET failed_attempts = 4 WHERE id = 9');
        $this->session['csrf_token'] = 'login-token';

        $response = $this->router()->dispatch(new ServerRequest('POST', '/admin/login', [], ['email' => 'admin@example.test', 'password' => 'correct horse', 'csrf_token' => 'login-token']));

        self::assertSame(303, $response->status);
        $statement = $this->pdo->query('SELECT failed_attempts FROM admin_users WHERE id = 9');
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function test_login_page_classes_have_dedicated_admin_styles(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('.login-shell{', $css);
        self::assertStringContainsString('.login-card{', $css);
        self::assertStringContainsString('.login-error{', $css);
    }

    private function router(): Router
    {
        return Router::auth(new AuthController($this->pdo, $this->session, new Csrf($this->session)));
    }
}
