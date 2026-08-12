<?php
declare(strict_types=1);
namespace HolyMD\Queue;

use HolyMD\Content\ArticleDocument;
use PDO;
use Throwable;

final readonly class MySqlJobQueue
{
    public function __construct(private PDO $pdo) {}

    public function enqueueBuild(ArticleDocument $article, string $action): int
    {
        return $this->transaction(function () use ($article, $action): int {
            $articleId = $this->articleId($article);
            $this->pdo->prepare("INSERT INTO builds (status) VALUES ('queued')")->execute();
            $buildId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO jobs (job_type, status, article_id, build_id, action) VALUES ('build', 'queued', ?, ?, ?)")->execute([$articleId, $buildId, $action]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    public function enqueueGeoReview(ArticleDocument $article, string $snapshotPath): int
    {
        return $this->transaction(function () use ($article, $snapshotPath): int {
            $articleId = $this->articleId($article);
            $checksum = hash('sha256', $article->bodyMarkdown);
            $this->pdo->prepare('INSERT INTO article_versions (article_id, snapshot_path, content_checksum) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$articleId, $snapshotPath, $checksum]);
            $versionId = (int) $this->pdo->lastInsertId();
            $requestKey = hash('sha256', $articleId . ':' . $versionId . ':' . $checksum);
            $this->pdo->prepare("INSERT INTO geo_reviews (article_id, article_version_id, status, provider, model, input_checksum, request_key) VALUES (?, ?, 'queued', 'configured', 'configured', ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute([$articleId, $versionId, $checksum, $requestKey]);
            $reviewId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO jobs (job_type, status, article_id, geo_review_id) SELECT 'geo_review', 'queued', ?, ? WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE geo_review_id = ? AND status IN ('queued','running','succeeded'))")->execute([$articleId, $reviewId, $reviewId]);
            return (int) ($this->pdo->lastInsertId() ?: $reviewId);
        });
    }

    private function articleId(ArticleDocument $article): int
    {
        $metadata = hash('sha256', json_encode($article->frontMatter->all(), JSON_THROW_ON_ERROR));
        $this->pdo->prepare("INSERT INTO articles (source_path, slug, state, metadata_checksum) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), slug=VALUES(slug), state=VALUES(state), metadata_checksum=VALUES(metadata_checksum)")->execute([$article->sourcePath, $article->slug, (string) $article->frontMatter->get('status', 'draft'), $metadata]);
        return (int) $this->pdo->lastInsertId();
    }

    private function transaction(callable $work): int
    {
        $this->pdo->beginTransaction();
        try { $result = $work(); $this->pdo->commit(); return $result; }
        catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }
}
