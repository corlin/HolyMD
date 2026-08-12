<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use RuntimeException;
/** Durable review/job records for a shared-host worker. */
final class FileGeoReviewStore implements GeoReviewStore, GeoProposalStore {
    public function __construct(private string $root) {}
    public function saveReview(GeoReview $review): void { $this->append('reviews.jsonl', ['article_slug' => $review->articleSlug, 'body_hash' => $review->bodyHash, 'status' => $review->status, 'proposals' => array_map(static fn (GeoProposal $p): array => ['id' => $p->id->value, 'type' => $p->type, 'value' => $p->value, 'status' => $p->status], $review->proposals), 'findings' => $review->findings, 'created_at' => gmdate(DATE_ATOM)]); }
    public function enqueueRetry(string $articleSlug, string $bodyHash, string $reason): void { $this->append('jobs.jsonl', ['job_type' => 'geo_review', 'status' => 'queued', 'article_slug' => $articleSlug, 'body_hash' => $bodyHash, 'attempts' => 0, 'available_at' => gmdate(DATE_ATOM), 'last_error' => $reason]); }
    /** @var array<string,GeoProposal> */ private array $proposals = [];
    public function get(GeoProposalId $id): GeoProposal { return $this->proposals[$id->value] ?? throw new \InvalidArgumentException('GEO proposal was not found.'); }
    public function save(GeoProposal $proposal): void { $this->proposals[$proposal->id->value] = $proposal; $this->append('proposals.jsonl', ['id' => $proposal->id->value, 'article_slug' => $proposal->articleSlug, 'body_hash' => $proposal->bodyHash, 'type' => $proposal->type, 'value' => $proposal->value, 'status' => $proposal->status]); }
    public function markAccepted(GeoProposalId $id): void { $p = $this->get($id); $this->save(new GeoProposal($p->id, $p->articleSlug, $p->bodyHash, $p->type, $p->value, 'accepted')); }
    public function markRejected(GeoProposalId $id): void { $p = $this->get($id); $this->save(new GeoProposal($p->id, $p->articleSlug, $p->bodyHash, $p->type, $p->value, 'rejected')); }
    /** @param array<string,mixed> $record */ private function append(string $file, array $record): void {
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) throw new RuntimeException('Unable to create GEO persistence directory.');
        if (file_put_contents($this->root . '/' . $file, json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('Unable to persist GEO review state.');
    }
}
