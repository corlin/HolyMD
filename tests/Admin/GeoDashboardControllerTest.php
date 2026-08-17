<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\GeoDashboardController;
use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\GeoScoreCalculator;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class GeoDashboardControllerTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $session = [];
    private string $contentDir;
    private ArticleRepository $repo;

    protected function setUp(): void
    {
        $this->contentDir = sys_get_temp_dir() . '/holymd-geo-dashboard-' . bin2hex(random_bytes(6));
        mkdir($this->contentDir, 0777, true);
        $this->repo = new ArticleRepository($this->contentDir);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE geo_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL,
            score INTEGER NOT NULL,
            breakdown TEXT NOT NULL,
            snapshot_trigger TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        // Add dummy snapshots
        $this->pdo->exec("INSERT INTO geo_scores (slug, score, breakdown, snapshot_trigger, created_at) VALUES ('guide', 85, '[]', 'publish', '2026-08-10 10:00:00')");
        $this->pdo->exec("INSERT INTO geo_scores (slug, score, breakdown, snapshot_trigger, created_at) VALUES ('guide', 90, '[]', 'publish', '2026-08-15 10:00:00')");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->contentDir);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/geo'));
        self::assertSame(401, $response->status);
    }

    public function test_dashboard_renders_with_articles_and_trends(): void
    {
        $this->session = ['admin_user_id' => 1, 'csrf_token' => 'test-token'];

        // Write a test article
        $this->repo->write(new ArticleDocument(
            'demo',
            'Demo Article',
            'Body markdown.',
            new FrontMatter([
                'title' => 'Demo Article',
                'slug' => 'demo',
                'date' => '2026-08-17',
                'status' => 'published',
                'summary' => 'Detailed summary of article with more than fifty characters to test.',
                'topics' => ['AI', 'Architecture'],
                'entities' => "DeepSeek\nLLMs",
            ]),
            $this->contentDir . '/demo.md'
        ));

        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/geo'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('GEO 健康度看板', $response->body);
        self::assertStringContainsString('Demo Article', $response->body);
        self::assertStringContainsString('全站平均 GEO 得分', $response->body);
        self::assertStringContainsString('历史发布健康度趋势', $response->body);
        self::assertStringContainsString('品牌主题权威分布', $response->body);
        self::assertStringContainsString('核心知识图谱实体', $response->body);
        self::assertStringContainsString('DeepSeek', $response->body);
        self::assertStringContainsString('Architecture', $response->body);
    }

    private function router(): Router
    {
        $calc = new GeoScoreCalculator();
        $controller = new GeoDashboardController(
            $this->repo,
            $calc,
            new AdminGuard($this->session),
            new Csrf($this->session),
            $this->pdo
        );
        return new Router(geoDashboard: $controller);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            $full = $path . '/' . $file;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }
}
