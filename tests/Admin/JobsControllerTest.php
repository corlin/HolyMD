<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\JobsController;
use HolyMD\Auth\AdminGuard;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use HolyMD\Queue\JobStatusRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class JobsControllerTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, slug TEXT)');
        $this->pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_type TEXT NOT NULL, status TEXT NOT NULL, action TEXT NULL, article_id INTEGER NULL, geo_review_id INTEGER NULL, build_id INTEGER NULL, attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO articles (id, slug) VALUES (1, 'heart-health')");
        $this->pdo->exec("INSERT INTO jobs (id, job_type, status, action, article_id, attempts, last_error, created_at, updated_at) VALUES (1, 'build', 'failed', 'publish', 1, 3, '<script>alert(1)</script>', '2026-08-13 00:00:00.000000', '2026-08-13 00:05:00.000000')");
        $this->pdo->exec("INSERT INTO jobs (id, job_type, status, action, article_id, attempts, last_error, created_at, updated_at) VALUES (2, 'build', 'succeeded', 'publish', 1, 1, NULL, '2026-08-13 01:00:00.000000', '2026-08-13 01:01:00.000000')");
        $this->pdo->exec("INSERT INTO jobs (id, job_type, status, action, article_id, attempts, last_error, created_at, updated_at) VALUES (3, 'geo_review', 'queued', NULL, 1, 0, NULL, '2026-08-13 02:00:00.000000', '2026-08-13 02:00:00.000000')");
    }

    public function test_jobs_page_requires_administrator_authentication(): void
    {
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/jobs'));

        self::assertSame(401, $response->status);
    }

    public function test_jobs_page_shows_summary_and_recent_jobs_with_escaped_errors(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'token'];
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/jobs'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('build failed: 1', $response->body);
        self::assertStringContainsString('build succeeded: 1', $response->body);
        self::assertStringContainsString('heart-health', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        self::assertStringContainsString('href="/admin/jobs"', $response->body);
    }

    public function test_summary_groups_by_type_and_status(): void
    {
        $summary = (new JobStatusRepository($this->pdo))->summary();

        self::assertSame([
            ['job_type' => 'build', 'status' => 'failed', 'count' => 1],
            ['job_type' => 'build', 'status' => 'succeeded', 'count' => 1],
            ['job_type' => 'geo_review', 'status' => 'queued', 'count' => 1],
        ], $summary);
    }

    public function test_recent_orders_by_id_desc_and_joins_slug(): void
    {
        $recent = (new JobStatusRepository($this->pdo))->recent();

        self::assertCount(3, $recent);
        self::assertSame(3, (int) $recent[0]['id']);
        self::assertSame('heart-health', $recent[0]['slug']);
        self::assertSame(1, (int) $recent[2]['id']);
    }

    private function router(): Router
    {
        return new Router(jobs: new JobsController(new JobStatusRepository($this->pdo), new AdminGuard($this->session), new Csrf($this->session)));
    }
}
