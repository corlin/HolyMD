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
        $this->pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NOT NULL)');
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
