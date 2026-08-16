<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\AiResponse;
use HolyMD\Geo\GeoReviewService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GeoReviewServiceTest extends TestCase
{
    public function test_review_sends_the_saved_article_context_to_an_analysis_only_prompt_and_returns_typed_proposals(): void
    {
        $client = new RecordingAiClient('{"proposals":[{"type":"summary","value":"A concise factual summary."},{"type":"metadata","value":{"topics":["PHP"]}}],"findings":["Add a descriptive alt text."]}');
        $document = new ArticleDocument('first-note', 'First note', "# Exact saved body\n", new FrontMatter(['title' => 'First note', 'slug' => 'first-note', 'date' => '2026-08-12']), '/articles/first-note.md');

        $review = (new GeoReviewService($client))->review($document);

        self::assertStringContainsString('DO NOT draft, rewrite, paraphrase, or return article prose', $client->systemPrompt);
        self::assertStringContainsString('JSON only', $client->systemPrompt);
        self::assertStringContainsString("title: 'First note'", $client->articleMarkdown);
        self::assertStringContainsString('date: \'2026-08-12\'', $client->articleMarkdown);
        self::assertStringContainsString('# Exact saved body', $client->articleMarkdown);
        self::assertSame(hash('sha256', $document->bodyMarkdown), $review->bodyHash);
        self::assertSame('summary', $review->proposals[0]->type);
        self::assertSame('A concise factual summary.', $review->proposals[0]->value);
        self::assertSame(['Add a descriptive alt text.'], $review->findings);
    }

    public function test_review_rejects_malformed_or_body_drafting_responses(): void
    {
        $document = new ArticleDocument('first-note', 'First note', "Body\n", new FrontMatter(['title' => 'First note', 'slug' => 'first-note', 'date' => '2026-08-12']), '/articles/first-note.md');

        $this->expectException(InvalidArgumentException::class);
        (new GeoReviewService(new RecordingAiClient('{"proposals":[{"type":"body","value":"Here is your rewritten article"}],"findings":[]}')))->review($document);
    }

    public function test_review_rejects_body_fields_even_when_the_proposal_type_is_allowed(): void
    {
        $document = new ArticleDocument('first-note', 'First note', "Body\n", new FrontMatter(['title' => 'First note', 'slug' => 'first-note', 'date' => '2026-08-12']), '/articles/first-note.md');
        foreach (['body', 'content', 'markdown', 'body_markdown'] as $field) {
            $this->expectException(InvalidArgumentException::class);
            (new GeoReviewService(new RecordingAiClient(json_encode(['proposals' => [['type' => 'metadata', 'value' => [$field => 'rewrite']]], 'findings' => []], JSON_THROW_ON_ERROR))))->review($document);
        }
    }

    public function test_review_handles_raw_string_value_json(): void
    {
        $client = new RecordingAiClient('{"proposals":[{"type":"summary","value_json":"A plain text summary without quotes"},{"type":"entities","value_json":"PHP\nMarkdown\nStatic"}],"findings":["Clean notes."]}');
        $document = new ArticleDocument('first-note', 'First note', "# Exact saved body\n", new FrontMatter(['title' => 'First note', 'slug' => 'first-note', 'date' => '2026-08-12']), '/articles/first-note.md');

        $review = (new GeoReviewService($client))->review($document);

        self::assertSame('summary', $review->proposals[0]->type);
        self::assertSame('A plain text summary without quotes', $review->proposals[0]->value);
        self::assertSame(['PHP', 'Markdown', 'Static'], $review->proposals[1]->value);
    }
}

final class RecordingAiClient implements AiClient
{
    public string $systemPrompt = '';
    public string $articleMarkdown = '';

    public function __construct(private readonly string $json)
    {
    }

    public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse
    {
        $this->systemPrompt = $systemPrompt;
        $this->articleMarkdown = $articleMarkdown;

        return new AiResponse($this->json);
    }
}
