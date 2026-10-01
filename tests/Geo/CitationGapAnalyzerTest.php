<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\AiResponse;
use HolyMD\Geo\CitationGapAnalyzer;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CitationGapAnalyzerTest extends TestCase
{
    private string $root;
    private PDO $pdo;
    private ArticleRepository $articles;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-gap-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->articles = new ArticleRepository($this->root);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE citation_probes (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL, question_hash TEXT NOT NULL, question TEXT NOT NULL, model TEXT NOT NULL, cited_site INTEGER NOT NULL, cited_article INTEGER NOT NULL, mentioned INTEGER NOT NULL, cited_url TEXT NULL, citations TEXT NOT NULL, answer_text TEXT NULL, gap_analysis TEXT NULL, analyzed_at TEXT NULL, error TEXT NULL, created_at TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function test_analyzes_citation_gap_and_persists_result(): void
    {
        $this->articles->write(new ArticleDocument(
            'what-is-geo',
            'What is GEO',
            "# What is GEO\n\nGEO is Generative Engine Optimization.",
            new FrontMatter(['title' => 'What is GEO', 'slug' => 'what-is-geo', 'date' => '2026-10-01']),
            $this->root . '/what-is-geo.md'
        ));

        $this->pdo->exec("INSERT INTO citation_probes (slug, question_hash, question, model, cited_site, cited_article, mentioned, cited_url, citations, answer_text, created_at)
            VALUES ('what-is-geo', 'hash123', 'What is GEO in marketing?', 'sonar', 0, 0, 0, NULL, '[\"https://competitor.com/geo-guide\"]', 'GEO refers to Generative Engine Optimization for AI models.', '2026-10-01 00:00:00')");

        $fakeClient = new class implements AiClient {
            public string $receivedSystem = '';
            public string $receivedUser = '';

            public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse
            {
                return new AiResponse('{}');
            }

            public function complete(string $systemPrompt, string $userMessage): string
            {
                $this->receivedSystem = $systemPrompt;
                $this->receivedUser = $userMessage;
                return "### AI 采纳的关键事实\nAI 强调了 marketing 角度。\n\n### 本文差距\n本文未提及营销场景。\n\n### 优化建议\n补充 GEO 在市场营销中的应用案例。";
            }
        };

        $analyzer = new CitationGapAnalyzer($this->pdo, $this->articles, $fakeClient);
        $result = $analyzer->analyze(1);

        self::assertStringContainsString('AI 采纳的关键事实', $result);
        self::assertStringContainsString('What is GEO in marketing?', $fakeClient->receivedUser);
        self::assertStringContainsString('https://competitor.com/geo-guide', $fakeClient->receivedUser);

        // Verify persisted in DB
        $row = $this->pdo->query('SELECT gap_analysis, analyzed_at FROM citation_probes WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($row['analyzed_at']);
        self::assertSame($result, $row['gap_analysis']);

        // Verify second call returns cached result without invoking client again
        $client2 = new class implements AiClient {
            public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { return new AiResponse('{}'); }
            public function complete(string $systemPrompt, string $userMessage): string {
                throw new \RuntimeException('Should not be called when cached');
            }
        };
        $cachedResult = (new CitationGapAnalyzer($this->pdo, $this->articles, $client2))->analyze(1);
        self::assertSame($result, $cachedResult);
    }

    public function test_throws_when_probe_is_missing_or_has_no_answer_text(): void
    {
        $fakeClient = new class implements AiClient {
            public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { return new AiResponse('{}'); }
            public function complete(string $systemPrompt, string $userMessage): string { return ''; }
        };
        $analyzer = new CitationGapAnalyzer($this->pdo, $this->articles, $fakeClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Citation probe #999 not found.');
        $analyzer->analyze(999);
    }

    public function test_throws_when_answer_text_is_empty(): void
    {
        $this->pdo->exec("INSERT INTO citation_probes (slug, question_hash, question, model, cited_site, cited_article, mentioned, cited_url, citations, answer_text, created_at)
            VALUES ('what-is-geo', 'h', 'Q?', 'sonar', 0, 0, 0, NULL, '[]', NULL, '2026-10-01 00:00:00')");

        $fakeClient = new class implements AiClient {
            public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { return new AiResponse('{}'); }
            public function complete(string $systemPrompt, string $userMessage): string { return ''; }
        };
        $analyzer = new CitationGapAnalyzer($this->pdo, $this->articles, $fakeClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not contain AI search answer text');
        $analyzer->analyze(1);
    }
}
