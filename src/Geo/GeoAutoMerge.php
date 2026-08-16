<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;

final class GeoAutoMerge
{
    /**
     * Merge GEO proposals into an article's front matter, filling only empty fields
     * and preserving all existing manual user edits. The Markdown body is never modified.
     *
     * @param list<GeoProposal> $proposals
     */
    public static function mergeDocument(ArticleDocument $document, array $proposals): ArticleDocument
    {
        $updatedFrontMatter = self::mergeFrontMatter($document->frontMatter, $proposals);
        return $document->withFrontMatter($updatedFrontMatter);
    }

    /**
     * @param list<GeoProposal> $proposals
     */
    public static function mergeFrontMatter(FrontMatter $frontMatter, array $proposals): FrontMatter
    {
        $result = $frontMatter;
        foreach ($proposals as $proposal) {
            $type = $proposal->type;
            $value = $proposal->value;

            $key = match ($type) {
                'summary' => 'summary',
                'entities' => 'entities',
                'faq_candidates' => 'faq',
                'alt_text' => 'alt_text',
                'sources' => 'sources',
                'internal_links' => 'internal_links',
                'structured_data' => 'structured_data',
                default => null,
            };

            if ($key === null || !self::isEmpty($result->get($key))) {
                continue;
            }

            if ($key === 'summary' && is_string($value) && trim($value) !== '') {
                $result = $result->with('summary', trim($value));
            } elseif ($key === 'entities' && is_array($value) && $value !== []) {
                $clean = array_values(array_filter(array_map('trim', array_filter($value, 'is_string')), static fn (string $s): bool => $s !== ''));
                if ($clean !== []) {
                    $result = $result->with('entities', $clean);
                }
            } elseif ($key === 'faq' && is_array($value) && $value !== []) {
                $result = $result->with('faq', $value);
            } elseif (in_array($key, ['alt_text', 'sources', 'internal_links'], true) && is_array($value) && $value !== []) {
                $clean = array_values(array_filter(array_map('trim', array_filter($value, 'is_string')), static fn (string $s): bool => $s !== ''));
                if ($clean !== []) {
                    $result = $result->with($key, $clean);
                }
            } elseif ($key === 'structured_data' && is_array($value) && $value !== []) {
                $result = $result->with('structured_data', $value);
            }
        }

        return $result;
    }

    public static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }
        return false;
    }
}
