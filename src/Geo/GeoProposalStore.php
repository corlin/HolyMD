<?php
declare(strict_types=1);
namespace HolyMD\Geo;

use HolyMD\Content\ArticleDocument;

interface GeoProposalStore
{
    public function get(string $id): GeoProposal;
    public function save(GeoProposal $proposal): void;
    public function markAccepted(string $id, string $nextInputChecksum, ?int $administratorId = null): void;
    public function markRejected(string $id, ?int $administratorId = null): void;

    /** @return list<GeoProposal> */
    public function recordReview(ArticleDocument $document, ?string $snapshotPath, GeoReview $review): array;

    /** @return array{reviewId:int,status:string,failure:?string,proposals:list<array<string,mixed>>}|null */
    public function latestReview(string $articleSlug): ?array;

    public function saveReview(GeoReview $review): void;
    public function enqueueRetry(string $articleSlug, string $bodyHash, string $reason): void;
}
