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

        $this->pdo->exec('CREATE TABLE ai_bot_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bot_name TEXT NOT NULL,
            request_path TEXT NOT NULL,
            http_status INTEGER NOT NULL,
            ip_hash TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE ai_referrals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source TEXT NOT NULL,
            landing_path TEXT NOT NULL,
            http_status INTEGER NOT NULL,
            referrer_host TEXT NULL,
            created_at TEXT NOT NULL
        )');

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->exec("INSERT INTO ai_bot_visits (bot_name, request_path, http_status, ip_hash, user_agent, created_at) VALUES ('GPTBot', '/llms.txt', 200, 'hash1', 'GPTBot/1.0', '{$now}')");
        $this->pdo->exec("INSERT INTO ai_bot_visits (bot_name, request_path, http_status, ip_hash, user_agent, created_at) VALUES ('PerplexityBot', '/articles/demo/', 200, 'hash2', 'PerplexityBot/1.0', '{$now}')");
    }

    protected function tearDown(): void
    {
        \HolyMD\I18n\Translator::setLocale('en');
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

        $english = $this->router()->dispatch(new ServerRequest('GET', '/admin/geo'));
        self::assertStringContainsString('<html lang="en">', $english->body);
        self::assertStringContainsString('GEO health dashboard', $english->body);
        self::assertStringContainsString('Average GEO score', $english->body);
        self::assertStringContainsString('AI crawler observability', $english->body);

        \HolyMD\I18n\Translator::setLocale('zh-CN');
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/geo'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<html lang="zh-CN">', $response->body);
        self::assertStringContainsString('GEO 健康度看板', $response->body);
        self::assertStringContainsString('Demo Article', $response->body);
        self::assertStringContainsString('全站平均 GEO 得分', $response->body);
        self::assertStringContainsString('发布健康度历史快照', $response->body);
        self::assertStringContainsString('品牌主题与实体矩阵', $response->body);
        self::assertStringContainsString('DeepSeek', $response->body);
        self::assertStringContainsString('Architecture', $response->body);
        self::assertStringContainsString('AI 爬虫可观测性', $response->body);
        self::assertStringContainsString('GPTBot', $response->body);
        self::assertStringContainsString('PerplexityBot', $response->body);
        self::assertStringContainsString('/llms.txt', $response->body);
    }

    public function test_dashboard_lines_up_ai_referrals_and_crawls_with_geo_scores(): void
    {
        $this->session = ['admin_user_id' => 1, 'csrf_token' => 'test-token'];
        $this->repo->write(new ArticleDocument('demo', 'Demo Article', 'Body markdown.', new FrontMatter(['title' => 'Demo Article', 'slug' => 'demo', 'date' => '2026-08-17', 'status' => 'published']), $this->contentDir . '/demo.md'));
        $now = gmdate('Y-m-d H:i:s');
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $this->pdo->exec("INSERT INTO ai_referrals (source, landing_path, http_status, referrer_host, created_at) VALUES ('ChatGPT', '/articles/demo/', 200, 'chatgpt.com', '{$now}'), ('ChatGPT', '/articles/demo/', 200, NULL, '{$now}'), ('Perplexity', '/articles/gone/', 404, 'www.perplexity.ai', '{$now}'), ('Kimi', '/articles/demo/', 200, 'kimi.com', '{$old}')");

        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/geo'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('AI referrals', $response->body);
        self::assertStringContainsString('<strong>ChatGPT</strong>', $response->body);
        self::assertStringNotContainsString('<strong>Kimi</strong>', $response->body);
        self::assertMatchesRegularExpression('#<span class="geo-ai-stat-num">3</span>\s*<span class="geo-ai-stat-lbl">Referrals, last 7 days#', $response->body);
        self::assertMatchesRegularExpression('#<span class="geo-ai-stat-num">1</span>\s*<span class="geo-ai-stat-lbl">Cited dead links#', $response->body);
        self::assertMatchesRegularExpression('#Demo Article</a></th>\s*<td><span class="geo-score-badge is-[a-z]+">\d+</span></td>\s*<td>1</td>\s*<td>2</td>#', $response->body);
    }

    public function test_compares_average_ai_visibility_above_and_below_the_excellent_grade(): void
    {
        $article = new ArticleDocument('a', 'A', 'Body.', new FrontMatter(['title' => 'A', 'slug' => 'a', 'date' => '2026-09-25']), 'a.md');
        $comparison = GeoDashboardController::compareByScore([
            ['article' => $article, 'score' => new \HolyMD\Geo\GeoScore(95, []), 'crawls' => 4, 'referrals' => 3],
            ['article' => $article, 'score' => new \HolyMD\Geo\GeoScore(85, []), 'crawls' => 2, 'referrals' => 0],
            ['article' => $article, 'score' => new \HolyMD\Geo\GeoScore(40, []), 'crawls' => 1, 'referrals' => 0],
        ]);

        self::assertSame(['articles' => 2, 'crawls' => 3.0, 'referrals' => 1.5], $comparison['high']);
        self::assertSame(['articles' => 1, 'crawls' => 1.0, 'referrals' => 0.0], $comparison['low']);
        self::assertSame(['articles' => 0, 'crawls' => 0.0, 'referrals' => 0.0], GeoDashboardController::compareByScore([])['low']);
    }

    public function test_dashboard_displays_utc_visit_time_in_the_site_timezone(): void
    {
        $this->session = ['admin_user_id' => 1, 'csrf_token' => 'test-token'];
        $this->pdo->exec("DELETE FROM ai_bot_visits");
        $this->pdo->exec("INSERT INTO ai_bot_visits (bot_name, request_path, http_status, ip_hash, user_agent, created_at) VALUES ('GPTBot', '/llms.txt', 200, 'hash1', 'GPTBot/1.0', '2026-08-17 12:34:56')");

        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/geo'));

        self::assertStringContainsString('08-17 20:34', $response->body);
        self::assertStringNotContainsString('08-17 12:34', $response->body);
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
