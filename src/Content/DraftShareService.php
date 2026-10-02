<?php

declare(strict_types=1);

namespace HolyMD\Content;

use PDO;
use RuntimeException;

final readonly class DraftShareService
{
    public function __construct(
        private ?PDO $pdo = null,
    ) {
    }

    /**
     * @return array{token: string, expires_at: ?string}
     */
    public function createShare(string $slug, ?int $expiresInSeconds = null): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection required for draft share management.');
        }

        $token = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = $expiresInSeconds !== null ? gmdate('Y-m-d H:i:s', time() + $expiresInSeconds) : null;

        $stmt = $this->pdo->prepare('INSERT INTO draft_shares (slug, token, expires_at, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$slug, $token, $expiresAt, $now]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public function validateToken(string $slug, string $token): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT id, expires_at, revoked_at FROM draft_shares WHERE slug = ? AND token = ? LIMIT 1');
        $stmt->execute([$slug, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return false;
        }

        if ($row['revoked_at'] !== null) {
            return false;
        }

        if ($row['expires_at'] !== null) {
            $expiresTime = strtotime((string) $row['expires_at'] . ' UTC');
            if ($expiresTime === false || $expiresTime <= time()) {
                return false;
            }
        }

        return true;
    }

    public function revokeShare(string $slug, string $token): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE draft_shares SET revoked_at = ? WHERE slug = ? AND token = ? AND revoked_at IS NULL');
        $stmt->execute([$now, $slug, $token]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{token: string, expires_at: ?string, created_at: string, revoked_at: ?string, is_active: bool}>
     */
    public function listShares(string $slug): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT token, expires_at, created_at, revoked_at FROM draft_shares WHERE slug = ? ORDER BY id DESC');
        $stmt->execute([$slug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $results = [];
        foreach ($rows as $row) {
            $expiresAt = is_string($row['expires_at']) ? $row['expires_at'] : null;
            $revokedAt = is_string($row['revoked_at']) ? $row['revoked_at'] : null;
            $createdAt = is_string($row['created_at']) ? $row['created_at'] : '';

            $isActive = $revokedAt === null;
            if ($isActive && $expiresAt !== null) {
                $expiresTime = strtotime($expiresAt . ' UTC');
                if ($expiresTime === false || $expiresTime <= $now) {
                    $isActive = false;
                }
            }

            $results[] = [
                'token' => (string) $row['token'],
                'expires_at' => $expiresAt,
                'created_at' => $createdAt,
                'revoked_at' => $revokedAt,
                'is_active' => $isActive,
            ];
        }

        return $results;
    }
}
