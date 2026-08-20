<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Content\ArticleDocument;
use InvalidArgumentException;
use JsonException;

final readonly class GeoReviewService
{
    public function __construct(private AiClient $client)
    {
    }

    public function review(ArticleDocument $document): GeoReview
    {
        $hash = hash('sha256', $document->bodyMarkdown);
        try {
            $rawJson = $this->client->analyze(GeoPrompt::system(), $document->serialize())->json;
            $payload = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
            return $this->validatedReview($payload, $document->slug, $hash);
        } catch (JsonException|InvalidArgumentException $exception) {
            throw new InvalidArgumentException('GEO AI response is malformed or unsafe: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /** @param mixed $payload */
    private function validatedReview(mixed $payload, string $slug, string $hash): GeoReview
    {
        if (!is_array($payload) || !isset($payload['proposals']) || !is_array($payload['proposals'])) {
            throw new InvalidArgumentException('Response must contain a list of proposals.');
        }

        $typeAliases = [
            'faq' => 'faq_candidates',
            'faq_candidate' => 'faq_candidates',
            'faqs' => 'faq_candidates',
            'keywords' => 'entities',
            'tags' => 'entities',
            'topic' => 'metadata',
            'topics' => 'metadata',
        ];

        $proposals = [];
        foreach ($payload['proposals'] as $index => $proposal) {
            if (!is_array($proposal)) {
                continue;
            }

            $rawType = $proposal['type'] ?? null;
            if (!is_string($rawType) || trim($rawType) === '') {
                continue;
            }
            $type = strtolower(trim($rawType));
            if ($type === 'body') {
                throw new InvalidArgumentException('GEO proposal cannot have type "body".');
            }
            if (isset($typeAliases[$type])) {
                $type = $typeAliases[$type];
            }

            if (!in_array($type, GeoReview::TYPES, true)) {
                continue;
            }

            // Extract proposal value
            $value = null;
            if (array_key_exists('value', $proposal)) {
                $value = $proposal['value'];
            } elseif (array_key_exists('value_json', $proposal)) {
                $vj = $proposal['value_json'];
                if (is_array($vj)) {
                    $value = $vj;
                } elseif (is_string($vj)) {
                    $raw = trim($vj);
                    try {
                        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $value = $raw;
                    }
                } else {
                    $value = $vj;
                }
            }

            if ($value === null) {
                continue;
            }

            // Check forbidden keys (e.g. body, markdown, content in metadata)
            if (self::containsForbiddenKey($value)) {
                throw new InvalidArgumentException('GEO proposal contains forbidden body/content keys.');
            }

            // If value is a JSON string for structured types, try decoding it
            if (is_string($value) && in_array($type, ['faq_candidates', 'structured_data', 'metadata'], true)) {
                $trimmed = trim($value);
                if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                    try {
                        $value = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        // ignore
                    }
                }
            }

            // If list type is given as string, split into array
            if (is_string($value) && in_array($type, ['entities', 'sources', 'alt_text', 'internal_links'], true)) {
                $lines = array_values(array_filter(
                    array_map('trim', preg_split('/[\r\n]+/', $value) ?: []),
                    static fn (string $line): bool => $line !== ''
                ));
                if ($lines !== []) {
                    $value = $lines;
                }
            }

            if (!$this->validValue($type, $value)) {
                continue;
            }

            $proposals[] = new GeoProposal('review-' . substr($hash, 0, 16) . '-' . $index, $slug, $hash, $type, $value);
        }

        // Extract findings
        $findings = [];
        if (isset($payload['findings']) && is_array($payload['findings'])) {
            foreach ($payload['findings'] as $finding) {
                if (is_string($finding)) {
                    $trimmed = trim($finding);
                    if ($trimmed !== '') {
                        $findings[] = $trimmed;
                    }
                } elseif (is_array($finding)) {
                    $findings[] = json_encode($finding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return new GeoReview($slug, $hash, $proposals, $findings);
    }

    private function validValue(string $type, mixed $value): bool
    {
        if (self::containsForbiddenKey($value)) {
            return false;
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && !is_array($item) && $item !== null) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    public static function containsForbiddenKey(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower(preg_replace('/[^a-z0-9_]/i', '', $key)), ['body', 'content', 'markdown', 'body_markdown', 'bodymarkdown', 'rewrite'], true)) {
                return true;
            }
            if (self::containsForbiddenKey($item)) {
                return true;
            }
        }
        return false;
    }
}
