<?php
declare(strict_types=1);
namespace HolyMD\Geo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class MySqlGeoProposalStore implements GeoProposalStore
{
    public function __construct(private PDO $pdo) {}

    public function get(GeoProposalId $id): GeoProposal
    {
        $query = $this->pdo->prepare('SELECT geo_proposals.id, geo_proposals.proposal_type, geo_proposals.proposed_metadata, geo_proposals.status, articles.slug, geo_reviews.input_checksum, article_versions.body_checksum FROM geo_proposals INNER JOIN geo_reviews ON geo_reviews.id = geo_proposals.geo_review_id INNER JOIN articles ON articles.id = geo_reviews.article_id INNER JOIN article_versions ON article_versions.id = geo_reviews.article_version_id WHERE geo_proposals.id = ?');
        $query->execute([$id->value]);
        $row = $query->fetch();
        if (!is_array($row)) throw new InvalidArgumentException('GEO proposal was not found.');
        $value = json_decode((string) $row['proposed_metadata'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value) && !is_string($value)) throw new RuntimeException('Stored GEO proposal metadata is invalid.');
        return new GeoProposal(new GeoProposalId((string) $row['id']), (string) $row['slug'], (string) $row['input_checksum'], (string) $row['proposal_type'], $value, (string) $row['status'], (string) $row['body_checksum']);
    }

    public function save(GeoProposal $proposal): void
    {
        $json = json_encode($proposal->value, JSON_THROW_ON_ERROR);
        $update = $this->pdo->prepare("UPDATE geo_proposals SET proposed_metadata = ?, proposal_key = ? WHERE id = ? AND status = 'pending'");
        $update->execute([$json, hash('sha256', $proposal->id->value . ':' . $proposal->type . ':' . $json), $proposal->id->value]);
        if ($update->rowCount() !== 1) throw new InvalidArgumentException('Only a pending GEO proposal can be edited.');
    }

    /** @return array{reviewId:int,status:string,failure:?string,proposals:list<array<string,mixed>>}|null */
    public function latestForArticle(string $slug): ?array
    {
        $review = $this->pdo->prepare('SELECT geo_reviews.id, geo_reviews.status, geo_reviews.failure_message FROM geo_reviews INNER JOIN articles ON articles.id = geo_reviews.article_id WHERE articles.slug = ? ORDER BY geo_reviews.id DESC LIMIT 1');
        $review->execute([$slug]); $row = $review->fetch(); if (!is_array($row)) return null;
        $query = $this->pdo->prepare('SELECT id FROM geo_proposals WHERE geo_review_id = ? ORDER BY id'); $query->execute([$row['id']]);
        $proposals = [];
        foreach ($query->fetchAll() as $proposalRow) { $proposal = $this->get(new GeoProposalId((string) $proposalRow['id'])); $proposals[] = ['id' => $proposal->id->value, 'type' => $proposal->type, 'value' => $proposal->value, 'status' => $proposal->status]; }
        return ['reviewId' => (int) $row['id'], 'status' => (string) $row['status'], 'failure' => $row['failure_message'] === null ? null : (string) $row['failure_message'], 'proposals' => $proposals];
    }

    public function markAccepted(GeoProposalId $id): void { $this->mark($id, 'accepted'); }
    public function markRejected(GeoProposalId $id): void { $this->mark($id, 'rejected'); }
    public function saveReview(GeoReview $review): void { throw new RuntimeException('Queue GEO reviews are persisted by the worker transaction.'); }
    public function enqueueRetry(string $articleSlug, string $bodyHash, string $reason): void { throw new RuntimeException('Queue retries are managed by the MySQL worker.'); }
    private function mark(GeoProposalId $id, string $status): void { $statement = $this->pdo->prepare("UPDATE geo_proposals SET status = ?, decided_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'pending'"); $statement->execute([$status, $id->value]); if ($statement->rowCount() !== 1) throw new InvalidArgumentException('Only a pending GEO proposal can be decided.'); }
}
