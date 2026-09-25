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
use HolyMD\Config\SiteTimezone;
use PDO;

final readonly class GeoDashboardController
{
    private AdminTimeFormatter $timeFormatter;

    public function __construct(
        private ArticleRepository $articles,
        private GeoScoreCalculator $calculator,
        private AdminGuard $guard,
        private Csrf $csrf,
        private ?PDO $pdo = null,
        ?AdminTimeFormatter $timeFormatter = null,
    ) {
        $this->timeFormatter = $timeFormatter ?? new AdminTimeFormatter(SiteTimezone::fromEnvironment());
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

        // Human visitors sent by AI assistants, and how they line up with GEO scores
        $aiReferralStats = $this->fetchAiReferralStats();
        $visibility = $this->fetchArticleVisibility($articleScores);
        $visibilityComparison = self::compareByScore($visibility);

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
                    'created_at_display' => $this->timeFormatter->format((string) $rr['created_at'], 'm-d H:i'),
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
     * @return array{
     *   total7d: int,
     *   distinctSources7d: int,
     *   deadLinks7d: int,
     *   sourceDistribution: list<array{source: string, count: int, percentage: int}>,
     *   topLandingPages: list<array{path: string, count: int}>
     * }
     */
    private function fetchAiReferralStats(): array
    {
        $default = ['total7d' => 0, 'distinctSources7d' => 0, 'deadLinks7d' => 0, 'sourceDistribution' => [], 'topLandingPages' => []];
        if ($this->pdo === null) {
            return $default;
        }

        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) AS total_7d, COUNT(DISTINCT source) AS sources_7d, SUM(CASE WHEN http_status = 404 THEN 1 ELSE 0 END) AS dead_7d
                 FROM ai_referrals WHERE created_at >= ?'
            );
            $statement->execute([$cutoff]);
            $summary = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            $total7d = (int) ($summary['total_7d'] ?? 0);

            $sources = [];
            $statement = $this->pdo->prepare('SELECT source, COUNT(*) AS count FROM ai_referrals WHERE created_at >= ? GROUP BY source ORDER BY count DESC');
            $statement->execute([$cutoff]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $count = (int) $row['count'];
                $sources[] = ['source' => (string) $row['source'], 'count' => $count, 'percentage' => $total7d > 0 ? (int) round($count / $total7d * 100) : 0];
            }

            $landingPages = [];
            $statement = $this->pdo->prepare('SELECT landing_path, COUNT(*) AS count FROM ai_referrals WHERE created_at >= ? GROUP BY landing_path ORDER BY count DESC LIMIT 5');
            $statement->execute([$cutoff]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $landingPages[] = ['path' => (string) $row['landing_path'], 'count' => (int) $row['count']];
            }

            return [
                'total7d' => $total7d,
                'distinctSources7d' => (int) ($summary['sources_7d'] ?? 0),
                'deadLinks7d' => (int) ($summary['dead_7d'] ?? 0),
                'sourceDistribution' => $sources,
                'topLandingPages' => $landingPages,
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * AI crawls and AI referrals per published article over the last 30 days.
     *
     * @param array<string, array{article: ArticleDocument, score: GeoScore}> $articleScores
     * @return list<array{article: ArticleDocument, score: GeoScore, crawls: int, referrals: int}>
     */
    private function fetchArticleVisibility(array $articleScores): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $crawls = $this->countArticlePaths('SELECT request_path AS path, COUNT(*) AS count FROM ai_bot_visits WHERE created_at >= ? GROUP BY request_path', $cutoff);
        $referrals = $this->countArticlePaths('SELECT landing_path AS path, COUNT(*) AS count FROM ai_referrals WHERE created_at >= ? AND http_status <> 404 GROUP BY landing_path', $cutoff);

        $rows = [];
        foreach ($articleScores as $slug => $entry) {
            $rows[] = [...$entry, 'crawls' => $crawls[$slug] ?? 0, 'referrals' => $referrals[$slug] ?? 0];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['referrals'], $b['crawls'], $b['score']->total] <=> [$a['referrals'], $a['crawls'], $a['score']->total]);
        return $rows;
    }

    /** @return array<string, int> Article slug => visits */
    private function countArticlePaths(string $sql, string $cutoff): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$cutoff]);
            $counts = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (preg_match('#^/articles/([a-z0-9]+(?:-[a-z0-9]+)*)(?:/(?:index\.html)?)?$#', (string) $row['path'], $matches) === 1) {
                    $counts[$matches[1]] = ($counts[$matches[1]] ?? 0) + (int) $row['count'];
                }
            }
            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Average AI crawls and referrals per article, split at the "excellent"
     * GEO grade. A first, correlation-only view of whether GEO work pays off.
     *
     * @param list<array{article: ArticleDocument, score: GeoScore, crawls: int, referrals: int}> $visibility
     * @return array{high: array{articles: int, crawls: float, referrals: float}, low: array{articles: int, crawls: float, referrals: float}}
     */
    public static function compareByScore(array $visibility): array
    {
        $groups = ['high' => ['articles' => 0, 'crawls' => 0, 'referrals' => 0], 'low' => ['articles' => 0, 'crawls' => 0, 'referrals' => 0]];
        foreach ($visibility as $row) {
            $group = $row['score']->total >= 80 ? 'high' : 'low';
            $groups[$group]['articles']++;
            $groups[$group]['crawls'] += $row['crawls'];
            $groups[$group]['referrals'] += $row['referrals'];
        }
        $average = static fn (array $group): array => [
            'articles' => $group['articles'],
            'crawls' => $group['articles'] > 0 ? round($group['crawls'] / $group['articles'], 1) : 0.0,
            'referrals' => $group['articles'] > 0 ? round($group['referrals'] / $group['articles'], 1) : 0.0,
        ];
        return ['high' => $average($groups['high']), 'low' => $average($groups['low'])];
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
