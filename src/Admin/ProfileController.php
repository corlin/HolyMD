<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\Unauthorized;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use InvalidArgumentException;
use PDO;

final readonly class ProfileController
{
    public function __construct(
        private PDO $pdo,
        private AdminGuard $guard,
        private Csrf $csrf,
    ) {
    }

    public function index(ServerRequest $request, ?string $error = null, ?string $success = null): Response
    {
        try {
            $adminId = $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }

        $statement = $this->pdo->prepare('SELECT email, display_name FROM admin_users WHERE id = ? LIMIT 1');
        $statement->execute([$adminId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            return Response::json(['error' => 'Administrator not found.'], 404);
        }

        $displayName = (string) ($user['display_name'] ?? '');
        $email = (string) ($user['email'] ?? '');
        $csrfToken = $this->csrf->token();

        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/profile.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function update(ServerRequest $request): Response
    {
        try {
            $adminId = $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }

        if (!$this->csrf->valid($request)) {
            return Response::json(['error' => 'CSRF token is invalid.'], 419);
        }

        $displayName = trim((string) $request->input('display_name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirmPassword = (string) $request->input('confirm_password', '');

        try {
            if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 255) {
                throw new InvalidArgumentException('A display name up to 255 characters is required.');
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 320) {
                throw new InvalidArgumentException('A valid email address is required.');
            }

            // Check email uniqueness against other admins
            $checkEmail = $this->pdo->prepare('SELECT id FROM admin_users WHERE email = ? AND id != ? LIMIT 1');
            $checkEmail->execute([$email, $adminId]);
            if ($checkEmail->fetch() !== false) {
                throw new InvalidArgumentException('This email is already in use by another administrator.');
            }

            $userStmt = $this->pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ? LIMIT 1');
            $userStmt->execute([$adminId]);
            $currentHash = $userStmt->fetchColumn();
            if (!is_string($currentHash)) {
                throw new InvalidArgumentException('Administrator record was not found.');
            }

            if ($newPassword !== '' || $currentPassword !== '') {
                if ($currentPassword === '' || !password_verify($currentPassword, $currentHash)) {
                    throw new InvalidArgumentException('Current password is incorrect.');
                }
                if (strlen($newPassword) < 12) {
                    throw new InvalidArgumentException('The new password must be at least 12 characters.');
                }
                if ($newPassword !== $confirmPassword) {
                    throw new InvalidArgumentException('New password and confirmation do not match.');
                }
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $this->pdo->prepare('UPDATE admin_users SET display_name = ?, email = ?, password_hash = ?, failed_attempts = 0 WHERE id = ?');
                $update->execute([$displayName, $email, $newHash, $adminId]);
            } else {
                $update = $this->pdo->prepare('UPDATE admin_users SET display_name = ?, email = ? WHERE id = ?');
                $update->execute([$displayName, $email, $adminId]);
            }

            return $this->index($request, success: 'Profile updated successfully.');
        } catch (InvalidArgumentException $e) {
            return $this->index($request, error: $e->getMessage());
        }
    }
}
