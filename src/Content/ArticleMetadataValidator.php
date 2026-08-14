<?php

declare(strict_types=1);

namespace HolyMD\Content;

use DateTimeImmutable;
use Throwable;

final readonly class ArticleMetadataValidator
{
    /** @return list<string> */
    public static function errors(ArticleDocument $document): array
    {
        $errors = [];
        $slug = $document->slug;
        $date = (string) $document->frontMatter->get('date');
        try {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
                $errors[] = sprintf('Article "%s" has an invalid date.', $slug);
            }
        } catch (Throwable) {
            $errors[] = sprintf('Article "%s" has an invalid date.', $slug);
        }
        foreach ((array) $document->frontMatter->get('previous_slugs', []) as $oldSlug) {
            if (!is_string($oldSlug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $oldSlug) !== 1) {
                $errors[] = sprintf('Article "%s" has an invalid historical slug.', $slug);
            }
        }
        foreach ((array) $document->frontMatter->get('sources', []) as $source) {
            if (!is_string($source) || !self::webUrl($source)) {
                $errors[] = sprintf('Article "%s" has an invalid citation URL.', $slug);
            }
        }
        $structured = $document->frontMatter->get('structured_data');
        if ($structured !== null && (!is_array($structured) || array_is_list($structured) || $structured === [] || self::containsForbiddenKey($structured))) {
            $errors[] = sprintf('Article "%s" has invalid structured data.', $slug);
        }
        foreach (['entities', 'faq', 'hierarchy', 'alt_text', 'internal_links'] as $freeKey) {
            $value = $document->frontMatter->get($freeKey);
            if ($value !== null && !is_string($value) && (!is_array($value) || self::containsForbiddenKey($value))) {
                $errors[] = sprintf('Article "%s" has invalid %s metadata.', $slug, $freeKey);
            }
        }
        foreach ((array) $document->frontMatter->get('topics', []) as $topic) {
            if (!is_string($topic) || trim($topic) === '') {
                $errors[] = sprintf('Article "%s" has an invalid topic.', $slug);
            }
        }
        $summary = $document->frontMatter->get('summary');
        if ($summary !== null && !is_string($summary)) {
            $errors[] = sprintf('Article "%s" has an invalid summary.', $slug);
        }
        return $errors;
    }

    public static function containsForbiddenKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            $normalized = is_string($key) ? strtolower((string) preg_replace('/[^a-z0-9_]/i', '', $key)) : '';
            if (in_array($normalized, ['body', 'content', 'markdown', 'body_markdown', 'bodymarkdown', 'rewrite'], true)) {
                return true;
            }
            if (is_array($item) && self::containsForbiddenKey($item)) {
                return true;
            }
        }
        return false;
    }

    private static function webUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
