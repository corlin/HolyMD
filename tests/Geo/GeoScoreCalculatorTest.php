<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\GeoScoreCalculator;
use HolyMD\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class GeoScoreCalculatorTest extends TestCase
{
    private GeoScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GeoScoreCalculator();
    }

    protected function tearDown(): void
    {
        Translator::setLocale('en');
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
        $this->assertSame('Excellent', $score->gradeLabel());
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
        $this->assertSame('Good', $score->gradeLabel());
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

    public function testMultilineEntitiesCountCorrectly(): void
    {
        $article = new ArticleDocument(
            'multiline-entities',
            'Entities Test',
            'Body text.',
            new FrontMatter([
                'date' => '2026-08-17',
                'entities' => "DeepSeek Harness\nCordis\nIBM JCL\nUNIX Pipe",
            ]),
            'multiline.md'
        );

        $score = $this->calculator->calculate($article);
        $entitiesField = $score->breakdown[3];
        $this->assertSame(10, $entitiesField['earned']);
        $this->assertSame('4 entities identified', $entitiesField['reason']);
    }

    public function testPlainTextFaqExplainsWhyItEarnsNoPoints(): void
    {
        foreach (['问题：一？二？', ['Only a question?']] as $faq) {
            $article = new ArticleDocument('plain', 'Plain', 'Body.', new FrontMatter(['date' => '2026-08-13', 'faq' => $faq]), 'plain.md');
            $field = $this->calculator->calculate($article)->breakdown[2];
            $this->assertSame(0, $field['earned']);
            $this->assertStringContainsString('question and answer pairs', $field['reason']);
        }
        $none = new ArticleDocument('none', 'None', 'Body.', new FrontMatter(['date' => '2026-08-13']), 'none.md');
        $this->assertSame('Missing FAQ pairs', $this->calculator->calculate($none)->breakdown[2]['reason']);
    }

    public function testAutoDetectsMarkdownBodyLinksForSourcesAndInternalLinks(): void
    {
        $article = new ArticleDocument(
            'body-links',
            'Links Test',
            "Refer to [IBM Docs](https://ibm.com/jcl) and [Unix Pipe](https://bell-labs.com/pipe).\nAlso check [Workflow Engine](/articles/workflow/) and [Plugin Model](/articles/plugin/).",
            new FrontMatter([
                'date' => '2026-08-17',
                // Front matter has empty sources and internal_links
                'sources' => [],
                'internal_links' => [],
            ]),
            'links.md'
        );

        $score = $this->calculator->calculate($article);
        $sourcesField = $score->breakdown[5];
        $internalField = $score->breakdown[6];

        $this->assertSame(10, $sourcesField['earned']);
        $this->assertStringContainsString('detected in the body', $sourcesField['reason']);

        $this->assertSame(10, $internalField['earned']);
        $this->assertStringContainsString('detected in the body', $internalField['reason']);

        Translator::setLocale('zh-CN');
        $chinese = $this->calculator->calculate($article);
        $this->assertSame('包含引用来源（2 条，已自动识别正文引用）', $chinese->breakdown[5]['reason']);
        $this->assertSame('站内互链', $chinese->breakdown[6]['label']);
        $this->assertSame('优秀', (new \HolyMD\Geo\GeoScore(90, []))->gradeLabel());
    }
}
