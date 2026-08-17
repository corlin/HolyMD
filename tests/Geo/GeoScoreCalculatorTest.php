<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\GeoScoreCalculator;
use PHPUnit\Framework\TestCase;

final class GeoScoreCalculatorTest extends TestCase
{
    private GeoScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GeoScoreCalculator();
    }

    public function testEmptyArticleScoresZeroExceptAltTextExemption(): void
    {
        $article = new ArticleDocument(
            'empty-slug',
            'Empty Title',
            'No images here.',
            new FrontMatter(['date' => '2026-08-17']),
            'empty-slug.md'
        );

        $score = $this->calculator->calculate($article);
        // Only alt_text exemption gives 5 points
        $this->assertSame(5, $score->total);
        $this->assertSame('weak', $score->grade());
    }

    public function testFullArticleScores100(): void
    {
        $article = new ArticleDocument(
            'full-article',
            'Full Article',
            'Here is some markdown text with an image: ![Demo Image](https://example.com/img.png)',
            new FrontMatter([
                'date' => '2026-08-17',
                'summary' => 'This is a comprehensive summary that contains well over fifty characters to reach the maximum score threshold.',
                'structured_data' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'BlogPosting',
                    'headline' => 'Full Article',
                ],
                'faq' => [
                    ['question' => 'What is GEO?', 'answer' => 'Generative Engine Optimization.'],
                    ['question' => 'How does it work?', 'answer' => 'By providing structured signals to AI models.'],
                ],
                'entities' => ['HolyMD', 'Markdown', 'PHP', 'SEO'],
                'topics' => ['Architecture', 'Technology'],
                'sources' => ['https://schema.org', 'https://w3.org'],
                'internal_links' => ['/articles/intro/', '/articles/guide/'],
                'alt_text' => ['Detailed diagram of system architecture'],
            ]),
            'full-article.md'
        );

        $score = $this->calculator->calculate($article);
        $this->assertSame(100, $score->total);
        $this->assertSame('excellent', $score->grade());
        $this->assertSame('优秀', $score->gradeLabel());
    }

    public function testPartialScores(): void
    {
        $article = new ArticleDocument(
            'partial-article',
            'Partial Article',
            'Body with image ![pic](img.jpg)',
            new FrontMatter([
                'date' => '2026-08-17',
                'summary' => 'Short summary', // 10
                'structured_data' => ['headline' => 'No type'], // 10
                'faq' => [['question' => 'Q1', 'answer' => 'A1']], // 8
                'entities' => ['One'], // 5
                'topics' => ['Tech'], // 10
                'sources' => ['https://example.com'], // 5
                'internal_links' => ['/about/'], // 5
                'alt_text' => [], // 0 (has image but no alt text)
            ]),
            'partial-article.md'
        );

        $score = $this->calculator->calculate($article);
        // 10 + 10 + 8 + 5 + 10 + 5 + 5 + 0 = 53
        $this->assertSame(53, $score->total);
        $this->assertSame('good', $score->grade());
        $this->assertSame('良好', $score->gradeLabel());
    }

    public function testImageWithoutAltTextScoresZeroForAltField(): void
    {
        $article = new ArticleDocument(
            'img-article',
            'Image Article',
            'Image: ![Photo](https://example.com/photo.jpg)',
            new FrontMatter(['date' => '2026-08-17']),
            'img-article.md'
        );

        $score = $this->calculator->calculate($article);
        $this->assertSame(0, $score->total);
    }
}
