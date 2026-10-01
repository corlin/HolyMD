<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Content\ArticleRepository;
use InvalidArgumentException;
use PDO;

final readonly class CitationGapAnalyzer
{
    public function __construct(
        private PDO $pdo,
        private ArticleRepository $articles,
        private AiClient $aiClient,
    ) {
    }

    /**
     * Analyzes why an AI search engine did not cite the article, and persists the advice in citation_probes.
     */
    public function analyze(int $probeId, bool $force = false): string
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, question, citations, answer_text, cited_article, gap_analysis, error FROM citation_probes WHERE id = ?');
        $stmt->execute([$probeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException(sprintf('Citation probe #%d not found.', $probeId));
        }

        if (!$force && !empty($row['gap_analysis'])) {
            return (string) $row['gap_analysis'];
        }

        $answerText = trim((string) ($row['answer_text'] ?? ''));
        if ($answerText === '') {
            throw new InvalidArgumentException(sprintf('Citation probe #%d does not contain AI search answer text to analyze.', $probeId));
        }

        $slug = (string) $row['slug'];
        $article = $this->articles->find($slug);
        if ($article === null) {
            throw new InvalidArgumentException(sprintf('Article "%s" was not found in articles repository.', $slug));
        }

        $citations = json_decode((string) ($row['citations'] ?? '[]'), true);
        $citationsList = is_array($citations) ? implode("\n- ", $citations) : '';
        if ($citationsList !== '') {
            $citationsList = "- " . $citationsList;
        }

        $systemPrompt = <<<PROMPT
You are a GEO (Generative Engine Optimization) expert.
An AI search engine answered a user query but did not cite this article, instead citing competitor sources.
Analyze the difference between what the AI search synthesized from competitors and what this article currently provides.
Provide a clear, actionable analysis in Markdown with these 3 sections:
1. **AI 采纳的关键事实与核心论据 (Key Facts & Angles in AI Answer)**: What specific data points, definitions, or core arguments did the AI search highlight that made it cite other sources?
2. **本文内容差距 (Content Gaps in This Article)**: What critical facts, clear definitions, or direct answering elements is this article missing or under-emphasizing?
3. **优化建议 (Actionable Recommendations)**: Concrete, specific suggestions for the author to add or refine in their Markdown content (e.g. data points to include, questions to answer directly, authoritative entities to reference) so that future AI searches cite this article.
Be concise, direct, and constructive. Respond in the language of the article (Simplified Chinese if the article is Chinese, English if English).
PROMPT;

        $userMessage = <<<MESSAGE
# Query / FAQ Question
{$row['question']}

# Synthesized Answer by AI Search
{$answerText}

# Competitor Sources Cited by AI Search
{$citationsList}

# Current Article Title
{$article->title}

# Current Article Markdown Content
{$article->bodyMarkdown}
MESSAGE;

        $analysis = $this->aiClient->complete($systemPrompt, $userMessage);

        $now = gmdate('Y-m-d H:i:s');
        $updateStmt = $this->pdo->prepare('UPDATE citation_probes SET gap_analysis = ?, analyzed_at = ? WHERE id = ?');
        $updateStmt->execute([$analysis, $now, $probeId]);

        return $analysis;
    }
}
