<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use PDO;
use Throwable;

/**
 * Asks an AI search model the FAQ questions of published articles and records
 * whether its answers cite this site. Questions that were probed least
 * recently go first, so repeated small runs cover every article over time.
 */
final readonly class CitationProbeRunner
{
    public function __construct(
        private ArticleRepository $articles,
        private CitationProbeClient $client,
        private PDO $pdo,
        private PublicationSettings $publication,
        private CitationProbeConfiguration $configuration,
    ) {
    }

    /** @return list<array{slug: string, question: string, result: ?CitationProbeResult, error: ?string}> */
    public function run(int $limit): array
    {
        $outcomes = [];
        foreach (array_slice($this->dueQuestions(), 0, max(0, $limit)) as $candidate) {
            $articleUrl = rtrim($this->publication->siteUrl, '/') . '/articles/' . $candidate['slug'] . '/';
            try {
                $answer = $this->client->ask($candidate['question']);
                $result = CitationProbeResult::evaluate($answer, $this->publication->siteUrl, $this->publication->siteName, $articleUrl);
                $this->record($candidate, $result, null);
                $outcomes[] = ['slug' => $candidate['slug'], 'question' => $candidate['question'], 'result' => $result, 'error' => null];
            } catch (Throwable $exception) {
                $error = $exception instanceof GeoAiException ? $exception->getMessage() : 'Citation probe failed.';
                $this->record($candidate, null, $error);
                $outcomes[] = ['slug' => $candidate['slug'], 'question' => $candidate['question'], 'result' => null, 'error' => $error];
            }
        }
        return $outcomes;
    }

    /**
     * FAQ questions of published articles, least recently probed successfully first.
     *
     * @return list<array{slug: string, question: string, hash: string}>
     */
    public function dueQuestions(): array
    {
        $candidates = [];
        foreach ($this->articles->all() as $article) {
            if ($article->frontMatter->get('status') !== 'published') {
                continue;
            }
            foreach (array_slice(self::questions($article), 0, $this->configuration->questionsPerArticle) as $question) {
                $candidates[] = ['slug' => $article->slug, 'question' => $question, 'hash' => hash('sha256', $article->slug . "\n" . $question)];
            }
        }

        $lastProbed = [];
        // Only successful probes count, so a question whose probe failed is retried first next time.
        $statement = $this->pdo->query('SELECT question_hash, MAX(created_at) AS last_probed FROM citation_probes WHERE error IS NULL GROUP BY question_hash');
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lastProbed[(string) $row['question_hash']] = (string) $row['last_probed'];
        }
        usort($candidates, static fn (array $a, array $b): int => ($lastProbed[$a['hash']] ?? '') <=> ($lastProbed[$b['hash']] ?? ''));
        return $candidates;
    }

    /** @return list<string> */
    public static function questions(ArticleDocument $article): array
    {
        $questions = [];
        foreach ((array) $article->frontMatter->get('faq', []) as $entry) {
            $question = is_array($entry) ? ($entry['question'] ?? null) : $entry;
            if (is_string($question) && trim($question) !== '') {
                $questions[] = mb_substr(trim($question), 0, 500);
            }
        }
        return array_values(array_unique($questions));
    }

    /** @param array{slug: string, question: string, hash: string} $candidate */
    private function record(array $candidate, ?CitationProbeResult $result, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO citation_probes (slug, question_hash, question, model, cited_site, cited_article, mentioned, cited_url, citations, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $candidate['slug'],
            $candidate['hash'],
            $candidate['question'],
            substr($this->configuration->model, 0, 100),
            $result?->citedSite ? 1 : 0,
            $result?->citedArticle ? 1 : 0,
            $result?->mentioned ? 1 : 0,
            $result?->citedUrl === null ? null : substr($result->citedUrl, 0, 768),
            json_encode(array_slice($result->citations ?? [], 0, 20), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $error === null ? null : mb_substr($error, 0, 500),
            gmdate('Y-m-d H:i:s'),
        ]);
    }
}
