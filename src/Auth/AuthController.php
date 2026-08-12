<?php

declare(strict_types=1);

namespace HolyMD\Auth;

use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use PDO;

final class AuthController
{
    /** @var array<string, mixed> */
    private array $session;

    /** @param array<string, mixed> $session */
    public function __construct(private readonly PDO $pdo, array &$session, private readonly Csrf $csrf)
    {
        $this->session =& $session;
    }

    public function login(ServerRequest $request): Response
    {
        if ($request->method === 'GET') {
            return $this->loginForm();
        }

        if (!$this->csrf->valid($request)) {
            return Response::json(['error' => 'CSRF token is invalid.'], 419);
        }

        $email = $request->input('email');
        $password = $request->input('password');
        if (!is_string($email) || !is_string($password)) {
            return $this->invalidCredentials();
        }
        $statement = $this->pdo->prepare('SELECT id, password_hash FROM admin_users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        $admin = $statement->fetch();
        if (!is_array($admin) || !is_string($admin['password_hash'] ?? null) || !password_verify($password, $admin['password_hash'])) {
            return $this->invalidCredentials();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->session['admin_user_id'] = (int) $admin['id'];
        $this->session['csrf_token'] = bin2hex(random_bytes(32));
        return Response::redirect('/admin/articles');
    }

    public function logout(ServerRequest $request): Response
    {
        if (!$this->csrf->valid($request)) {
            return Response::json(['error' => 'CSRF token is invalid.'], 419);
        }
        unset($this->session['admin_user_id'], $this->session['csrf_token']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return Response::redirect('/admin/login');
    }

    private function loginForm(?string $error = null, int $status = 200): Response
    {
        if (!is_string($this->session['csrf_token'] ?? null)) {
            $this->session['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrfToken = $this->session['csrf_token'];
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/login.php';
        return new Response($status, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function invalidCredentials(): Response
    {
        return $this->loginForm('Invalid email or password.', 422);
    }
}
