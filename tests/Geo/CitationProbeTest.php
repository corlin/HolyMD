<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\CitationProbeAnswer;
use HolyMD\Geo\CitationProbeClient;
use HolyMD\Geo\CitationProbeConfiguration;
use HolyMD\Geo\CitationProbeResult;
use HolyMD\Geo\CitationProbeRunner;
use HolyMD\Geo\EndpointPolicy;
use HolyMD\Geo\GeoAiException;
use HolyMD\Geo\HttpResponse;
use HolyMD\Geo\HttpTransport;
use PDO;
use PHPUnit\Framework\TestCase;

final class CitationProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-probe-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function test_parses_citations_from_perplexity_openai_and_answer_text(): void
    {
        $answer = CitationProbeClient::parse([
            'choices' => [['message' => [
                'content' => 'GEO means optimizing for AI answers [1]. See https://blog.example.org/geo. or ftp://bad.example',
                'annotations' => [['type' => 'url_citation', 'url_citation' => ['url' => 'https://openai-style.example/a']]],
            ]]],
            'citations' => ['https://www.example.com/articles/what-is-geo/', 'not a url'],
            'search_results' => [['url' => 'https://search.example/b'], ['title' => 'no url']],
        ]);

        self::assertStringStartsWith('GEO means', $answer->text);
        self::assertSame([
            'https://www.example.com/articles/what-is-geo/',
            'https://search.example/b',
            'https://openai-style.example/a',
            'https://blog.example.org/geo',
        ], $answer->citations);
    }

    public function test_sends_the_question_to_a_validated_endpoint_and_reports_http_errors(): void
    {
        $transport = new QueuedProbeTransport([new HttpResponse(200, json_encode(['choices' => [['message' => ['content' => 'Answer']]], 'citations' => ['https://example.com/']], JSON_THROW_ON_ERROR)), new HttpResponse(429, '{}')]);
        $client = new CitationProbeClient('secret', new CitationProbeConfiguration('https://probe.test/chat/completions', 'sonar', true, 20), $transport, new EndpointPolicy(static fn (string $host): array => ['8.8.8.8']));

        $answer = $client->ask('What is GEO?');

        self::assertSame(['https://example.com/'], $answer->citations);
        self::assertSame('Bearer secret', $transport->requests[0]['headers']['Authorization']);
        self::assertSame(['8.8.8.8'], $transport->requests[0]['addresses']);
        $body = json_decode($transport->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('sonar', $body['model']);
        self::assertSame('What is GEO?', $body['messages'][1]['content']);

        try {
            $client->ask('Again?');
            self::fail('HTTP 429 must raise a retryable error.');
        } catch (GeoAiException $exception) {
            self::assertTrue($exception->retryable);
        }
    }

    public function test_rejects_endpoints_that_resolve_to_private_addresses(): void
    {
        $client = new CitationProbeClient('secret', new CitationProbeConfiguration('https://probe.test/', 'sonar', true), new QueuedProbeTransport([]), new EndpointPolicy(static fn (string $host): array => ['10.0.0.5']));

        $this->expectException(GeoAiException::class);
        $client->ask('What is GEO?');
    }

    public function test_distinguishes_article_citations_site_citations_and_mentions(): void
    {
        $site = 'https://example.com';
        $article = 'https://example.com/articles/what-is-geo/';

        $cited = CitationProbeResult::evaluate(new CitationProbeAnswer('text', ['https://other.org/', 'https://www.example.com/about/', 'https://www.example.com/articles/what-is-geo']), $site, 'Example Notes', $article);
        self::assertTrue($cited->citedSite);
        self::assertTrue($cited->citedArticle);
        self::assertSame('https://www.example.com/articles/what-is-geo', $cited->citedUrl);

        $siteOnly = CitationProbeResult::evaluate(new CitationProbeAnswer('text', ['https://example.com/about/']), $site, 'Example Notes', $article);
        self::assertTrue($siteOnly->citedSite);
        self::assertFalse($siteOnly->citedArticle);
        self::assertSame('https://example.com/about/', $siteOnly->citedUrl);

        $mentioned = CitationProbeResult::evaluate(new CitationProbeAnswer('According to example notes, GEO…', ['https://notexample.com/']), $site, 'Example Notes', $article);
        self::assertFalse($mentioned->citedSite);
        self::assertTrue($mentioned->mentioned);
        self::assertNull($mentioned->citedUrl);

        $shortName = CitationProbeResult::evaluate(new CitationProbeAnswer('An AI answer', []), $site, 'AI', $article);
        self::assertFalse($shortName->mentioned, 'Names shorter than 4 characters are too ambiguous to count as mentions');
    }

    public function test_runner_probes_faq_questions_of_published_articles_least_recently_first(): void
    {
        $repository = new ArticleRepository($this->root);
        $faq = [['question' => 'What is GEO?', 'answer' => 'A.'], ['question' => 'Does GEO replace SEO?', 'answer' => 'No.'], ['question' => 'Third?', 'answer' => 'C.']];
        $repository->write(new ArticleDocument('what-is-geo', 'What is GEO', 'Body.', new FrontMatter(['title' => 'What is GEO', 'slug' => 'what-is-geo', 'date' => '2026-09-25', 'status' => 'published', 'faq' => $faq]), $this->root . '/what-is-geo.md'));
        $repository->write(new ArticleDocument('draft', 'Draft', 'Body.', new FrontMatter(['title' => 'Draft', 'slug' => 'draft', 'date' => '2026-09-25', 'status' => 'draft', 'faq' => $faq]), $this->root . '/draft.md'));
        $pdo = $this->database();
        $pdo->exec("INSERT INTO citation_probes (slug, question_hash, question, model, cited_site, cited_article, mentioned, cited_url, citations, error, created_at) VALUES ('what-is-geo', '" . hash('sha256', "what-is-geo\nWhat is GEO?") . "', 'What is GEO?', 'sonar', 0, 0, 0, NULL, '[]', NULL, '2026-09-01 00:00:00')");

        $transport = new QueuedProbeTransport([
            new HttpResponse(200, json_encode(['choices' => [['message' => ['content' => 'No.']]], 'citations' => ['https://www.example.com/articles/what-is-geo/']], JSON_THROW_ON_ERROR)),
            new HttpResponse(500, '{}'),
        ]);
        $configuration = new CitationProbeConfiguration('https://probe.test/', 'sonar', true, questionsPerArticle: 2);
        $runner = new CitationProbeRunner($repository, new CitationProbeClient('secret', $configuration, $transport, new EndpointPolicy(static fn (string $host): array => ['8.8.8.8'])), $pdo, new PublicationSettings('Example Notes', 'https://example.com', 'Ada', 'About.'), $configuration);

        self::assertSame(['Does GEO replace SEO?', 'What is GEO?'], array_column($runner->dueQuestions(), 'question'), 'Never-probed questions run first; drafts and questions past the per-article limit are skipped');

        $outcomes = $runner->run(5);

        self::assertCount(2, $outcomes);
        self::assertTrue($outcomes[0]['result']?->citedArticle);
        self::assertNull($outcomes[1]['result']);
        self::assertSame('Citation probe provider returned HTTP 500.', $outcomes[1]['error']);
        $rows = $pdo->query('SELECT question, cited_site, cited_article, cited_url, citations, error FROM citation_probes WHERE id > 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('Does GEO replace SEO?', $rows[0]['question']);
        self::assertSame(1, (int) $rows[0]['cited_article']);
        self::assertSame('https://www.example.com/articles/what-is-geo/', $rows[0]['cited_url']);
        self::assertSame('["https://www.example.com/articles/what-is-geo/"]', $rows[0]['citations']);
        self::assertSame('Citation probe provider returned HTTP 500.', $rows[1]['error']);
        self::assertSame(['What is GEO?', 'Does GEO replace SEO?'], array_column($runner->dueQuestions(), 'question'), 'The just-probed question moves to the back of the queue');
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE citation_probes (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL, question_hash TEXT NOT NULL, question TEXT NOT NULL, model TEXT NOT NULL, cited_site INTEGER NOT NULL, cited_article INTEGER NOT NULL, mentioned INTEGER NOT NULL, cited_url TEXT NULL, citations TEXT NOT NULL, error TEXT NULL, created_at TEXT NOT NULL)');
        return $pdo;
    }
}

final class QueuedProbeTransport implements HttpTransport
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, addresses: list<string>}> */
    public array $requests = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds, int $maxResponseBytes, array $resolvedAddresses = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'addresses' => $resolvedAddresses];
        return array_shift($this->responses) ?? new HttpResponse(500, '{}');
    }
}
