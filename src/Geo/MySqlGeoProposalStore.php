<?php
declare(strict_types=1);
namespace HolyMD\Geo;

use HolyMD\Content\ArticleDocument;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class MySqlGeoProposalStore implements GeoProposalStore
{
    public function __construct(private PDO $pdo) {}

    public function get(string $id): GeoProposal
    {
        $query = $this->pdo->prepare('SELECT geo_proposals.id, geo_proposals.proposal_type, geo_proposals.proposed_metadata, geo_proposals.status, articles.slug, geo_reviews.input_checksum, article_versions.body_checksum FROM geo_proposals INNER JOIN geo_reviews ON geo_reviews.id = geo_proposals.geo_review_id INNER JOIN articles ON articles.id = geo_reviews.article_id INNER JOIN article_versions ON article_versions.id = geo_reviews.article_version_id WHERE geo_proposals.id = ?');
        $query->execute([$id]);
        $row = $query->fetch();
        if (!is_array($row)) throw new InvalidArgumentException('GEO proposal was not found.');
        $value = json_decode((string) $row['proposed_metadata'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value) && !is_string($value)) throw new RuntimeException('Stored GEO proposal metadata is invalid.');
        return new GeoProposal((string) $row['id'], (string) $row['slug'], (string) $row['input_checksum'], (string) $row['proposal_type'], $value, (string) $row['status'], (string) $row['body_checksum']);
    }

    public function save(GeoProposal $proposal): void
    {
        $json = json_encode($proposal->value, JSON_THROW_ON_ERROR);
        $update = $this->pdo->prepare("UPDATE geo_proposals SET proposed_metadata = ?, proposal_key = ? WHERE id = ? AND status = 'pending'");
        $update->execute([$json, hash('sha256', $proposal->id . ':' . $proposal->type . ':' . $json), $proposal->id]);
        if ($update->rowCount() !== 1) throw new InvalidArgumentException('Only a pending GEO proposal can be edited.');
    }

    /** @return array{reviewId:int,status:string,failure:?string,proposals:list<array<string,mixed>>}|null */
    public function latestReview(string $slug): ?array
    {
        $review = $this->pdo->prepare('SELECT geo_reviews.id, geo_reviews.status, geo_reviews.failure_message FROM geo_reviews INNER JOIN articles ON articles.id = geo_reviews.article_id WHERE articles.slug = ? ORDER BY geo_reviews.id DESC LIMIT 1');
        $review->execute([$slug]); $row = $review->fetch(); if (!is_array($row)) return null;
        $query = $this->pdo->prepare('SELECT id FROM geo_proposals WHERE geo_review_id = ? ORDER BY id'); $query->execute([$row['id']]);
        $proposals = [];
        foreach ($query->fetchAll() as $proposalRow) { $proposal = $this->get((string) $proposalRow['id']); $proposals[] = ['id' => $proposal->id, 'type' => $proposal->type, 'value' => $proposal->value, 'status' => $proposal->status]; }
        return ['reviewId' => (int) $row['id'], 'status' => (string) $row['status'], 'failure' => $row['failure_message'] === null ? null : (string) $row['failure_message'], 'proposals' => $proposals];
    }

    public function markAccepted(string $id, string $nextInputChecksum, ?int $administratorId = null): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $nextInputChecksum) !== 1) throw new InvalidArgumentException('GEO proposal checksum is invalid.');
        $this->pdo->beginTransaction();
        try {
            $review = $this->pdo->prepare("SELECT geo_review_id FROM geo_proposals WHERE id = ? AND status = 'pending' FOR UPDATE");
            $review->execute([$id]);
            $reviewId = $review->fetchColumn();
            if ($reviewId === false) throw new InvalidArgumentException('Only a pending GEO proposal can be decided.');
            $this->mark($id, 'accepted', $administratorId);
            $update = $this->pdo->prepare('UPDATE geo_reviews SET input_checksum = ? WHERE id = ?');
            $update->execute([$nextInputChecksum, $reviewId]);
            $this->auditDecision($id, 'accepted', $administratorId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
    public function markRejected(string $id, ?int $administratorId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->mark($id, 'rejected', $administratorId);
            $this->auditDecision($id, 'rejected', $administratorId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
    /** Sync deployments (no queue) persist a completed review and its proposals inline, mirroring the worker's rows, so accept/edit/reject keep working on later requests. @return list<GeoProposal> */
    public function recordReview(ArticleDocument $document, ?string $snapshotPath, GeoReview $review): array
    {
        $metadata = hash('sha256', json_encode($document->frontMatter->all(), JSON_THROW_ON_ERROR));
        $checksum = hash('sha256', $document->serialize());
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO articles (source_path, slug, state, metadata_checksum) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), slug=VALUES(slug), state=VALUES(state), metadata_checksum=VALUES(metadata_checksum)")->execute([$document->sourcePath, $document->slug, (string) $document->frontMatter->get('status', 'draft'), $metadata]);
            $articleId = (int) $this->pdo->lastInsertId();
            if (!is_string($snapshotPath) || preg_match('#^review-inputs/[a-f0-9]{32}\.md$#', $snapshotPath) !== 1) throw new InvalidArgumentException('A GEO review requires an immutable review input snapshot.');
            $this->pdo->prepare('INSERT INTO article_versions (article_id, snapshot_path, content_checksum, body_checksum) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$articleId, $snapshotPath, $checksum, hash('sha256', $document->bodyMarkdown)]);
            $versionId = (int) $this->pdo->lastInsertId();
            $requestKey = hash('sha256', $articleId . ':' . $versionId . ':' . $checksum);
            $this->pdo->prepare("INSERT INTO geo_reviews (article_id, article_version_id, status, provider, model, input_checksum, request_key, completed_at) VALUES (?, ?, 'completed', 'configured', 'configured', ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute([$articleId, $versionId, $checksum, $requestKey]);
            $reviewId = (int) $this->pdo->lastInsertId();
            $persisted = [];
            $insert = $this->pdo->prepare("INSERT INTO geo_proposals (geo_review_id, proposal_type, proposed_metadata, proposal_key, status) VALUES (?, ?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            foreach ($review->proposals as $proposal) {
                $json = json_encode($proposal->value, JSON_THROW_ON_ERROR);
                $insert->execute([$reviewId, $proposal->type, $json, hash('sha256', $reviewId . ':' . $proposal->type . ':' . $json)]);
                $persisted[] = $this->get((string) $this->pdo->lastInsertId());
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        return $persisted;
    }

    private function mark(string $id, string $status, ?int $administratorId = null): void { $statement = $this->pdo->prepare("UPDATE geo_proposals SET status = ?, decision_by_admin_user_id = ?, decided_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'pending'"); $statement->execute([$status, $administratorId, $id]); if ($statement->rowCount() !== 1) throw new InvalidArgumentException('Only a pending GEO proposal can be decided.'); }
    private function auditDecision(string $id, string $status, ?int $administratorId): void
    {
        $statement = $this->pdo->prepare("INSERT INTO audit_events (admin_user_id, event_type, subject_type, subject_id, event_data) VALUES (?, 'geo_proposal_decided', 'geo_proposal', ?, ?)");
        $statement->execute([$administratorId, (int) $id, json_encode(['status' => $status], JSON_THROW_ON_ERROR)]);
    }
}
