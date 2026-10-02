<?php

declare(strict_types=1);

namespace HolyMD\Tests\Content;

use HolyMD\Content\DraftShareService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DraftShareServiceTest extends TestCase
{
    private PDO $pdo;
    private DraftShareService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE draft_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NULL,
            created_at TEXT NOT NULL,
            revoked_at TEXT NULL
        )');
        $this->service = new DraftShareService($this->pdo);
    }

    public function test_creates_and_validates_active_share_token(): void
    {
        $share = $this->service->createShare('test-article', 3600);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $share['token']);
        self::assertNotNull($share['expires_at']);
        self::assertTrue($this->service->validateToken('test-article', $share['token']));
        self::assertFalse($this->service->validateToken('wrong-article', $share['token']));
    }

    public function test_creates_permanent_share_token_when_expiry_is_null(): void
    {
        $share = $this->service->createShare('permanent-article', null);

        self::assertNull($share['expires_at']);
        self::assertTrue($this->service->validateToken('permanent-article', $share['token']));
    }

    public function test_expired_share_token_is_invalid(): void
    {
        $share = $this->service->createShare('expiring-article', -10);

        self::assertFalse($this->service->validateToken('expiring-article', $share['token']));
    }

    public function test_revoking_share_invalidates_token(): void
    {
        $share = $this->service->createShare('revocable-article', 3600);
        self::assertTrue($this->service->validateToken('revocable-article', $share['token']));

        $revoked = $this->service->revokeShare('revocable-article', $share['token']);
        self::assertTrue($revoked);
        self::assertFalse($this->service->validateToken('revocable-article', $share['token']));
    }

    public function test_lists_shares_for_slug_with_status(): void
    {
        $active = $this->service->createShare('listing-article', 3600);
        $revoked = $this->service->createShare('listing-article', 3600);
        $this->service->revokeShare('listing-article', $revoked['token']);

        $shares = $this->service->listShares('listing-article');

        self::assertCount(2, $shares);
        self::assertTrue($shares[1]['is_active']);
        self::assertFalse($shares[0]['is_active']);
    }
}
