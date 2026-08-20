<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use HolyMD\Content\ArticleDocument;
use InvalidArgumentException;
final class InMemoryGeoProposalStore implements GeoProposalStore
{
    /** @var array<string,GeoProposal> */
    private array $proposals = [];

    /** @var array<string,array{reviewId:int,status:string,failure:?string}> */
    private array $reviews = [];

    public function get(string $id): GeoProposal
    {
        return $this->proposals[$id] ?? throw new InvalidArgumentException('GEO proposal was not found.');
    }

    public function save(GeoProposal $proposal): void
    {
        $this->proposals[$proposal->id] = $proposal;
    }

    public function markAccepted(string $id, string $nextInputChecksum, ?int $administratorId = null): void
    {
        $selected = $this->get($id);
        foreach ($this->proposals as $key => $candidate) {
            if ($candidate->articleSlug === $selected->articleSlug && $candidate->inputChecksum === $selected->inputChecksum && $candidate->status === 'pending') {
                $this->proposals[$key] = new GeoProposal($candidate->id, $candidate->articleSlug, $nextInputChecksum, $candidate->type, $candidate->value, $candidate->id === $id ? 'accepted' : 'pending', $candidate->bodyHash);
            }
        }
    }

    public function markRejected(string $id, ?int $administratorId = null): void
    {
        $proposal = $this->get($id);
        $this->save(new GeoProposal($proposal->id, $proposal->articleSlug, $proposal->inputChecksum, $proposal->type, $proposal->value, 'rejected', $proposal->bodyHash));
    }

    public function recordReview(ArticleDocument $document, ?string $snapshotPath, GeoReview $review): array
    {
        $this->storeReview($review, $review->proposals);
        return $review->proposals;
    }

    public function latestReview(string $articleSlug): ?array
    {
        $review = $this->reviews[$articleSlug] ?? null;
        if ($review === null) return null;
        $proposals = array_filter($this->proposals, static fn (GeoProposal $proposal): bool => $proposal->articleSlug === $articleSlug);
        return [...$review, 'proposals' => array_values(array_map(static fn (GeoProposal $proposal): array => [
            'id' => $proposal->id,
            'type' => $proposal->type,
            'value' => $proposal->value,
            'status' => $proposal->status,
        ], $proposals))];
    }

    /** @param list<GeoProposal> $proposals */
    private function storeReview(GeoReview $review, array $proposals): void
    {
        foreach ($proposals as $proposal) $this->save($proposal);
        $this->reviews[$review->articleSlug] = [
            'reviewId' => count($this->reviews) + 1,
            'status' => $review->status,
            'failure' => null,
        ];
    }
}
