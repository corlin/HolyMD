<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use HolyMD\Content\ArticleDocument;
use InvalidArgumentException;
use JsonException;
final readonly class GeoReviewService {
    public function __construct(private AiClient $client, private ?GeoReviewStore $store = null) {}
    public function review(ArticleDocument $document): GeoReview {
        $hash = hash('sha256', $document->bodyMarkdown);
        try { $payload = json_decode($this->client->analyze(GeoPrompt::system(), $document->bodyMarkdown)->json, true, 512, JSON_THROW_ON_ERROR); $review = $this->validatedReview($payload, $document->slug, $hash); $this->store?->saveReview($review); return $review; }
        catch (JsonException|InvalidArgumentException $exception) { $this->store?->enqueueRetry($document->slug, $hash, $exception->getMessage()); throw new InvalidArgumentException('GEO AI response is malformed or unsafe: ' . $exception->getMessage(), previous: $exception); }
    }
    /** @param mixed $payload */ private function validatedReview(mixed $payload, string $slug, string $hash): GeoReview {
        if (!is_array($payload) || !array_is_list($payload['proposals'] ?? null) || !array_is_list($payload['findings'] ?? null)) throw new InvalidArgumentException('Response must contain proposal and finding lists.');
        $proposals = [];
        foreach ($payload['proposals'] as $index => $proposal) {
            if (!is_array($proposal) || !is_string($proposal['type'] ?? null) || !array_key_exists('value', $proposal)) throw new InvalidArgumentException('Every proposal requires a type and value.');
            if (!in_array($proposal['type'], GeoReview::TYPES, true) || (!is_array($proposal['value']) && !is_string($proposal['value']))) throw new InvalidArgumentException('Response contains an unsupported GEO proposal.');
            $proposals[] = new GeoProposal(new GeoProposalId('review-' . substr($hash, 0, 16) . '-' . $index), $slug, $hash, $proposal['type'], $proposal['value']);
        }
        foreach ($payload['findings'] as $finding) if (!is_string($finding)) throw new InvalidArgumentException('Every GEO finding must be text.');
        return new GeoReview($slug, $hash, $proposals, $payload['findings']);
    }
}
