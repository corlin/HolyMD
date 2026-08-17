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
            $entityList = [];
            if (is_string($entities)) {
                $entityList = preg_split('/[\r\n]+/', $entities) ?: [];
            } elseif (is_array($entities)) {
                $entityList = $entities;
            }
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

        $csrfToken = $this->csrf->token();
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/geo-dashboard.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
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
