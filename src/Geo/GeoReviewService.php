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
        if (!is_array($payload) || array_diff(array_keys($payload), ['proposals', 'findings']) !== [] || !array_is_list($payload['proposals'] ?? null) || !array_is_list($payload['findings'] ?? null)) throw new InvalidArgumentException('Response must contain only proposal and finding lists.');
        $proposals = [];
        foreach ($payload['proposals'] as $index => $proposal) {
            if (is_array($proposal) && isset($proposal['value_json']) && !isset($proposal['value'])) { try { $proposal['value'] = json_decode((string) $proposal['value_json'], true, 64, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new InvalidArgumentException('Proposal value_json is invalid JSON.'); } unset($proposal['value_json']); }
            if (!is_array($proposal) || !is_string($proposal['type'] ?? null) || !array_key_exists('value', $proposal)) throw new InvalidArgumentException('Every proposal requires a type and value.');
            if (!in_array($proposal['type'], GeoReview::TYPES, true) || array_diff(array_keys($proposal), ['type', 'value']) !== [] || !$this->validValue($proposal['type'], $proposal['value'])) throw new InvalidArgumentException('Response contains an unsupported GEO proposal.');
            $proposals[] = new GeoProposal(new GeoProposalId('review-' . substr($hash, 0, 16) . '-' . $index), $slug, $hash, $proposal['type'], $proposal['value']);
        }
        foreach ($payload['findings'] as $finding) if (!is_string($finding)) throw new InvalidArgumentException('Every GEO finding must be text.');
        return new GeoReview($slug, $hash, $proposals, $payload['findings']);
    }

    private function validValue(string $type, mixed $value): bool
    {
        if ($this->containsForbiddenKey($value)) return false;
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) return true;
        if (is_array($value)) {
            foreach ($value as $item) if (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && !is_array($item) && $item !== null) return false;
            return true;
        }
        return false;
    }

    private function containsForbiddenKey(mixed $value): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower(preg_replace('/[^a-z0-9_]/i', '', $key)), ['body', 'content', 'markdown', 'body_markdown', 'bodymarkdown', 'rewrite'], true)) return true;
            if ($this->containsForbiddenKey($item)) return true;
        }
        return false;
    }
}
