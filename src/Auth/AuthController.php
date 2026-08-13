<?php

declare(strict_types=1);

namespace HolyMD\Auth;

use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use PDO;

final class AuthController
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;

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
        $statement = $this->pdo->prepare('SELECT id, password_hash, failed_attempts, locked_until, is_active FROM admin_users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        $admin = $statement->fetch();
        if (!is_array($admin) || !is_string($admin['password_hash'] ?? null)) {
            return $this->invalidCredentials();
        }

        // Lockout timestamps use a fixed-width UTC format so string comparison is chronological.
        $now = gmdate('Y-m-d H:i:s.u');
        $lockedUntil = $admin['locked_until'] ?? null;
        if (is_string($lockedUntil) && $lockedUntil !== '' && $lockedUntil > $now) {
            // A 429 here reveals that the email exists; acceptable for a single-operator site.
            $minutes = max(1, (int) ceil((strtotime($lockedUntil) - time()) / 60));
            return $this->loginForm('Account temporarily locked. Try again in ' . $minutes . ' minutes.', 429);
        }
        // Disabled accounts answer like unknown credentials to avoid enumeration.
        if ((int) ($admin['is_active'] ?? 1) !== 1) {
            return $this->invalidCredentials();
        }
        if (!password_verify($password, $admin['password_hash'])) {
            $this->recordFailure((int) $admin['id']);
            return $this->invalidCredentials();
        }

        $this->pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([(int) $admin['id']]);
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

    private function recordFailure(int $adminId): void
    {
        // Two statements instead of a CASE expression: pdo_sqlite mangles bound
        // parameters inside CASE in UPDATE statements (tests run on sqlite).
        $lockedAt = gmdate('Y-m-d H:i:s.u', time() + self::LOCKOUT_SECONDS);
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE admin_users SET failed_attempts = failed_attempts + 1 WHERE id = ?')->execute([$adminId]);
        $this->pdo->prepare('UPDATE admin_users SET locked_until = ? WHERE id = ? AND failed_attempts >= ?')->execute([$lockedAt, $adminId, self::MAX_FAILED_ATTEMPTS]);
        $this->pdo->commit();
    }

    private function invalidCredentials(): Response
    {
        return $this->loginForm('Invalid email or password.', 422);
    }
}
