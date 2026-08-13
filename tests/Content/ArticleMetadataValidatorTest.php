<?php

declare(strict_types=1);

namespace HolyMD\Tests\Content;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleMetadataValidator;
use HolyMD\Content\FrontMatter;
use PHPUnit\Framework\TestCase;

final class ArticleMetadataValidatorTest extends TestCase
{
    private function document(array $metadata): ArticleDocument
    {
        $frontMatter = new FrontMatter(['title' => 'Article', 'slug' => 'article', 'date' => '2026-08-13', ...$metadata]);
        return new ArticleDocument('article', 'Article', "Body\n", $frontMatter, 'article.md');
    }

    public function test_a_clean_article_has_no_errors(): void
    {
        self::assertSame([], ArticleMetadataValidator::errors($this->document(['topics' => ['Health'], 'summary' => 'Summary', 'sources' => ['https://example.test/evidence']])));
    }

    public function test_empty_metadata_is_valid(): void
    {
        self::assertSame([], ArticleMetadataValidator::errors($this->document(['topics' => [], 'sources' => [], 'structured_data' => null])));
    }

    public function test_rejects_an_invalid_date(): void
    {
        self::assertContains('Article "article" has an invalid date.', ArticleMetadataValidator::errors($this->document(['date' => 'not-a-date'])));
    }

    public function test_rejects_invalid_historical_slugs(): void
    {
        self::assertContains('Article "article" has an invalid historical slug.', ArticleMetadataValidator::errors($this->document(['previous_slugs' => ['Bad Slug!']])));
        self::assertContains('Article "article" has an invalid historical slug.', ArticleMetadataValidator::errors($this->document(['previous_slugs' => [42]])));
        self::assertSame([], ArticleMetadataValidator::errors($this->document(['previous_slugs' => ['old-slug']])));
    }

    public function test_rejects_non_web_citation_urls(): void
    {
        self::assertContains('Article "article" has an invalid citation URL.', ArticleMetadataValidator::errors($this->document(['sources' => ['ftp://example.test/file']])));
        self::assertContains('Article "article" has an invalid citation URL.', ArticleMetadataValidator::errors($this->document(['sources' => ['not a url']])));
        self::assertSame([], ArticleMetadataValidator::errors($this->document(['sources' => ['https://example.test/evidence']])));
    }

    public function test_rejects_invalid_structured_data_shapes_and_forbidden_keys(): void
    {
        self::assertContains('Article "article" has invalid structured data.', ArticleMetadataValidator::errors($this->document(['structured_data' => 'plain string'])));
        self::assertContains('Article "article" has invalid structured data.', ArticleMetadataValidator::errors($this->document(['structured_data' => []])));
        self::assertContains('Article "article" has invalid structured data.', ArticleMetadataValidator::errors($this->document(['structured_data' => ['list', 'of', 'items']])));
        self::assertContains('Article "article" has invalid structured data.', ArticleMetadataValidator::errors($this->document(['structured_data' => ['@type' => 'Thing', 'body' => 'x']])));
        self::assertSame([], ArticleMetadataValidator::errors($this->document(['structured_data' => ['@type' => 'MedicalWebPage', 'about' => ['name' => 'Health']]])));
    }

    public function test_rejects_invalid_topics_and_summaries(): void
    {
        self::assertContains('Article "article" has an invalid topic.', ArticleMetadataValidator::errors($this->document(['topics' => ['']])));
        self::assertContains('Article "article" has an invalid topic.', ArticleMetadataValidator::errors($this->document(['topics' => [7]])));
        self::assertContains('Article "article" has an invalid summary.', ArticleMetadataValidator::errors($this->document(['summary' => ['not', 'a', 'string']])));
    }

    public function test_forbidden_key_detection_is_recursive_and_normalized(): void
    {
        self::assertTrue(ArticleMetadataValidator::containsForbiddenKey(['body' => 'x']));
        self::assertTrue(ArticleMetadataValidator::containsForbiddenKey(['nested' => ['Markdown' => 'x']]));
        self::assertTrue(ArticleMetadataValidator::containsForbiddenKey(['nested' => [['rewrite' => 'x']]]));
        self::assertFalse(ArticleMetadataValidator::containsForbiddenKey(['@type' => 'Thing', 'nested' => ['name' => 'ok']]));
    }
}
