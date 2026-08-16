<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\GeoAutoMerge;
use HolyMD\Geo\GeoProposal;
use PHPUnit\Framework\TestCase;

final class GeoAutoMergeTest extends TestCase
{
    public function testFillsEmptyFields(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'Sample Post',
            'slug' => 'sample-post',
            'date' => '2026-08-16',
        ]);
        $doc = new ArticleDocument('sample-post', 'Sample Post', "## Hello World\n\nContent here.", $frontMatter, 'sample-post.md');

        $hash = str_repeat('a', 64);
        $proposals = [
            new GeoProposal('p1', 'sample-post', $hash, 'summary', 'A great post about sample things.'),
            new GeoProposal('p2', 'sample-post', $hash, 'entities', ['PHP', 'GEO']),
            new GeoProposal('p3', 'sample-post', $hash, 'faq_candidates', [['question' => 'Q1?', 'answer' => 'A1.']]),
            new GeoProposal('p4', 'sample-post', $hash, 'sources', ['https://example.com']),
        ];

        $merged = GeoAutoMerge::mergeDocument($doc, $proposals);

        $this->assertSame('Sample Post', $merged->title);
        $this->assertSame("## Hello World\n\nContent here.", $merged->bodyMarkdown);
        $this->assertSame('A great post about sample things.', $merged->frontMatter->get('summary'));
        $this->assertSame(['PHP', 'GEO'], $merged->frontMatter->get('entities'));
        $this->assertSame([['question' => 'Q1?', 'answer' => 'A1.']], $merged->frontMatter->get('faq'));
        $this->assertSame(['https://example.com'], $merged->frontMatter->get('sources'));
    }

    public function testPreservesExistingUserFields(): void
    {
        $frontMatter = new FrontMatter([
            'title' => 'Custom Title',
            'slug' => 'custom-title',
            'date' => '2026-08-16',
            'summary' => 'My manual summary that must not change.',
            'entities' => ['ManualTag'],
        ]);
        $doc = new ArticleDocument('custom-title', 'Custom Title', 'Body text', $frontMatter, 'custom-title.md');

        $hash = str_repeat('a', 64);
        $proposals = [
            new GeoProposal('p1', 'custom-title', $hash, 'summary', 'AI generated summary.'),
            new GeoProposal('p2', 'custom-title', $hash, 'entities', ['AI', 'Generated']),
            new GeoProposal('p3', 'custom-title', $hash, 'faq_candidates', [['question' => 'Q?', 'answer' => 'A.']]),
        ];

        $merged = GeoAutoMerge::mergeDocument($doc, $proposals);

        // summary and entities must be preserved
        $this->assertSame('My manual summary that must not change.', $merged->frontMatter->get('summary'));
        $this->assertSame(['ManualTag'], $merged->frontMatter->get('entities'));
        // empty faq should be filled
        $this->assertSame([['question' => 'Q?', 'answer' => 'A.']], $merged->frontMatter->get('faq'));
    }
}
