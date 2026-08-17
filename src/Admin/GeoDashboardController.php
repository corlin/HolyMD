<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\Unauthorized;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Geo\GeoScore;
use HolyMD\Geo\GeoScoreCalculator;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use PDO;

final readonly class GeoDashboardController
{
    public function __construct(
        private ArticleRepository $articles,
        private GeoScoreCalculator $calculator,
        private AdminGuard $guard,
        private Csrf $csrf,
        private ?PDO $pdo = null,
    ) {
    }

    public function index(ServerRequest $request): Response
    {
        try {
            $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }

        $allArticles = $this->articles->all();
        $publishedArticles = array_values(array_filter(
            $allArticles,
            static fn (ArticleDocument $doc): bool => $doc->frontMatter->get('status', 'draft') === 'published'
        ));

        // Compute scores for each published article
        /** @var array<string, array{article: ArticleDocument, score: GeoScore}> $articleScores */
        $articleScores = [];
        $totalScoreSum = 0;
        $excellentCount = 0;
        $goodCount = 0;
        $weakCount = 0;

        /** @var array<string, array{name: string, count: int, totalScore: int, avgScore: int}> $topicStats */
        $topicStats = [];
        /** @var array<string, int> $entityCounts */
        $entityCounts = [];

        foreach ($publishedArticles as $article) {
            $score = $this->calculator->calculate($article);
            $articleScores[$article->slug] = ['article' => $article, 'score' => $score];
            $totalScoreSum += $score->total;
            match ($score->grade()) {
                'excellent' => $excellentCount++,
                'good' => $goodCount++,
                'weak' => $weakCount++,
            };

            // Aggregate topics
            $topics = (array) $article->frontMatter->get('topics', []);
            foreach ($topics as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $topicName = trim($t);
                    if (!isset($topicStats[$topicName])) {
                        $topicStats[$topicName] = ['name' => $topicName, 'count' => 0, 'totalScore' => 0, 'avgScore' => 0];
                    }
                    $topicStats[$topicName]['count']++;
                    $topicStats[$topicName]['totalScore'] += $score->total;
                }
            }

            // Aggregate entities
            $entities = $article->frontMatter->get('entities');
            $entityList = is_array($entities) ? $entities : (is_string($entities) ? (preg_split('/[\r\n,]+/', $entities) ?: []) : []);
            foreach ($entityList as $ent) {
                if (is_string($ent) && trim($ent) !== '') {
                    $normalized = trim($ent);
                    $entityCounts[$normalized] = ($entityCounts[$normalized] ?? 0) + 1;
                }
            }
        }

        foreach ($topicStats as $name => &$stat) {
            $stat['avgScore'] = (int) round($stat['totalScore'] / $stat['count']);
        }
        unset($stat);
        uasort($topicStats, static fn ($a, $b): int => $b['count'] <=> $a['count'] ?: $b['avgScore'] <=> $a['avgScore']);

        arsort($entityCounts);
        $topEntities = array_slice($entityCounts, 0, 30, true);

        $publishedCount = count($publishedArticles);
        $averageScore = $publishedCount > 0 ? (int) round($totalScoreSum / $publishedCount) : 0;
        $excellentPercentage = $publishedCount > 0 ? (int) round(($excellentCount / $publishedCount) * 100) : 0;

        // Weakest articles sorted ascending by score
        $weakest = $articleScores;
        usort($weakest, static fn ($a, $b): int => $a['score']->total <=> $b['score']->total);
        $topWeakest = array_slice($weakest, 0, 5);

        // Fetch recent score snapshots from database for trend chart
        $trends = $this->fetchTrends();

        // Fetch AI bot observability metrics
        $aiBotStats = $this->fetchAiBotStats();

        $csrfToken = $this->csrf->token();
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/geo-dashboard.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @return array{
     *   total7d: int,
     *   distinctBots7d: int,
     *   llmsTxt7d: int,
     *   botDistribution: list<array{bot_name: string, count: int, percentage: int}>,
     *   topPaths: list<array{path: string, count: int}>,
     *   recentVisits: list<array{id: int, bot_name: string, request_path: string, http_status: int, created_at: string}>
     * }
     */
    private function fetchAiBotStats(): array
    {
        $default = [
            'total7d' => 0,
            'distinctBots7d' => 0,
            'llmsTxt7d' => 0,
            'botDistribution' => [],
            'topPaths' => [],
            'recentVisits' => [],
        ];

        if ($this->pdo === null) {
            return $default;
        }

        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 7 * 86400);

            // 7d summary
            $stmt = $this->pdo->prepare(
                "SELECT 
                    COUNT(*) as total_7d,
                    COUNT(DISTINCT bot_name) as distinct_bots_7d,
                    SUM(CASE WHEN request_path LIKE '%llms%' THEN 1 ELSE 0 END) as llms_7d
                 FROM ai_bot_visits 
                 WHERE created_at >= ?"
            );
            $stmt->execute([$cutoff]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            $total7d = (int) ($summary['total_7d'] ?? 0);
            $distinctBots7d = (int) ($summary['distinct_bots_7d'] ?? 0);
            $llmsTxt7d = (int) ($summary['llms_7d'] ?? 0);

            // Bot distribution
            $botDist = [];
            if ($total7d > 0) {
                $stmt = $this->pdo->prepare(
                    "SELECT bot_name, COUNT(*) as count 
                     FROM ai_bot_visits 
                     WHERE created_at >= ?
                     GROUP BY bot_name 
                     ORDER BY count DESC"
                );
                $stmt->execute([$cutoff]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $cnt = (int) $r['count'];
                    $botDist[] = [
                        'bot_name' => (string) $r['bot_name'],
                        'count' => $cnt,
                        'percentage' => (int) round(($cnt / $total7d) * 100),
                    ];
                }
            }

            // Top crawled paths
            $topPaths = [];
            $stmt = $this->pdo->prepare(
                "SELECT request_path, COUNT(*) as count 
                 FROM ai_bot_visits 
                 WHERE created_at >= ?
                 GROUP BY request_path 
                 ORDER BY count DESC 
                 LIMIT 5"
            );
            $stmt->execute([$cutoff]);
            $pathRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pathRows as $pr) {
                $topPaths[] = [
                    'path' => (string) $pr['request_path'],
                    'count' => (int) $pr['count'],
                ];
            }

            // Recent visits
            $recentVisits = [];
            $stmt = $this->pdo->query(
                "SELECT id, bot_name, request_path, http_status, created_at 
                 FROM ai_bot_visits 
                 ORDER BY id DESC 
                 LIMIT 5"
            );
            $recentRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($recentRows as $rr) {
                $recentVisits[] = [
                    'id' => (int) $rr['id'],
                    'bot_name' => (string) $rr['bot_name'],
                    'request_path' => (string) $rr['request_path'],
                    'http_status' => (int) $rr['http_status'],
                    'created_at' => (string) $rr['created_at'],
                ];
            }

            return [
                'total7d' => $total7d,
                'distinctBots7d' => $distinctBots7d,
                'llmsTxt7d' => $llmsTxt7d,
                'botDistribution' => $botDist,
                'topPaths' => $topPaths,
                'recentVisits' => $recentVisits,
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @return list<array{date: string, score: int}>
     */
    private function fetchTrends(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $stmt = $this->pdo->query(
                "SELECT snapshot_date, avg_score FROM (
                     SELECT DATE(created_at) as snapshot_date, ROUND(AVG(score)) as avg_score 
                     FROM geo_scores 
                     GROUP BY DATE(created_at) 
                     ORDER BY snapshot_date DESC 
                     LIMIT 30
                 ) AS sub ORDER BY snapshot_date ASC"
            );
            if ($stmt === false) {
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $trends = [];
            foreach ($rows as $row) {
                $trends[] = [
                    'date' => (string) $row['snapshot_date'],
                    'score' => (int) $row['avg_score'],
                ];
            }
            return $trends;
        } catch (\Throwable) {
            return [];
        }
    }
}
