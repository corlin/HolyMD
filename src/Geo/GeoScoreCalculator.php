<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\I18n\Translator;

final class GeoScoreCalculator
{
    public function calculate(ArticleDocument $article): GeoScore
    {
        $breakdown = [];
        $total = 0;
        $fm = $article->frontMatter;

        // 1. Summary (20)
        $summary = trim((string) $fm->get('summary', ''));
        $summaryLen = mb_strlen($summary, 'UTF-8');
        if ($summaryLen >= 50) {
            $earned = 20;
            $reason = Translator::text('Detailed summary provided (50+ characters)');
        } elseif ($summaryLen > 0) {
            $earned = 10;
            $reason = Translator::text('Summary is short (under 50 characters); consider expanding it');
        } else {
            $earned = 0;
            $reason = Translator::text('Missing summary; AI and RSS indexing will suffer');
        }
        $breakdown[] = ['field' => 'summary', 'label' => Translator::text('Summary'), 'weight' => 20, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 2. Structured Data (20)
        $structured = $fm->get('structured_data');
        if (is_array($structured) && $structured !== []) {
            if (!empty($structured['@type']) || !empty($structured['type'])) {
                $earned = 20;
                $reason = Translator::text('Schema.org structured data configured');
            } else {
                $earned = 10;
                $reason = Translator::text('JSON-LD present but missing @type');
            }
        } else {
            $earned = 0;
            $reason = Translator::text('Missing JSON-LD structured data');
        }
        $breakdown[] = ['field' => 'structured_data', 'label' => Translator::text('Structured data (JSON-LD)'), 'weight' => 20, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 3. FAQ (15)
        $faq = $fm->get('faq');
        $faqCount = 0;
        if (is_array($faq)) {
            foreach ($faq as $item) {
                if (is_array($item) && !empty($item['question']) && !empty($item['answer'])) {
                    $faqCount++;
                }
            }
        }
        if ($faqCount >= 2) {
            $earned = 15;
            $reason = Translator::text('{count} FAQ pairs', ['count' => $faqCount]);
        } elseif ($faqCount === 1) {
            $earned = 8;
            $reason = Translator::text('Only 1 FAQ pair; add at least 2');
        } else {
            $earned = 0;
            $reason = Translator::text('Missing FAQ pairs');
        }
        $breakdown[] = ['field' => 'faq', 'label' => Translator::text('FAQ'), 'weight' => 15, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 4. Entities (10)
        $entities = $fm->get('entities');
        $entityList = $this->filterNonEmptyStrings($entities);
        $entityCount = count($entityList);
        if ($entityCount >= 3) {
            $earned = 10;
            $reason = Translator::text('{count} entities identified', ['count' => $entityCount]);
        } elseif ($entityCount > 0) {
            $earned = 5;
            $reason = Translator::text('Only {count} entities; aim for 3 or more', ['count' => $entityCount]);
        } else {
            $earned = 0;
            $reason = Translator::text('Missing named entities');
        }
        $breakdown[] = ['field' => 'entities', 'label' => Translator::text('Entities'), 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 5. Topics (10)
        $topics = $fm->get('topics');
        $topicList = $this->filterNonEmptyStrings($topics);
        if (count($topicList) >= 1) {
            $earned = 10;
            $reason = Translator::text('Topics: {topics}', ['topics' => implode(', ', $topicList)]);
        } else {
            $earned = 0;
            $reason = Translator::text('No topics assigned');
        }
        $breakdown[] = ['field' => 'topics', 'label' => Translator::text('Topics'), 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 6. Sources (10)
        $sources = $fm->get('sources');
        $sourceList = $this->filterNonEmptyStrings($sources);
        $bodySources = $this->extractBodyExternalUrls($article->bodyMarkdown);
        $allSources = array_values(array_unique([...$sourceList, ...$bodySources]));
        $sourceCount = count($allSources);
        if ($sourceCount >= 2) {
            $earned = 10;
            $reason = $bodySources !== []
                ? Translator::text('{count} sources, including links detected in the body', ['count' => $sourceCount])
                : Translator::text('{count} sources', ['count' => $sourceCount]);
        } elseif ($sourceCount === 1) {
            $earned = 5;
            $reason = Translator::text('Only 1 source; consider adding more');
        } else {
            $earned = 0;
            $reason = Translator::text('Missing sources (weak E-E-A-T signal)');
        }
        $breakdown[] = ['field' => 'sources', 'label' => Translator::text('Sources'), 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 7. Internal links (10)
        $internalLinks = $fm->get('internal_links');
        $linkList = $this->filterNonEmptyStrings($internalLinks);
        $bodyLinks = $this->extractBodyInternalLinks($article->bodyMarkdown);
        $allLinks = array_values(array_unique([...$linkList, ...$bodyLinks]));
        $linkCount = count($allLinks);
        if ($linkCount >= 2) {
            $earned = 10;
            $reason = $bodyLinks !== []
                ? Translator::text('{count} internal links, including links detected in the body', ['count' => $linkCount])
                : Translator::text('{count} internal links', ['count' => $linkCount]);
        } elseif ($linkCount === 1) {
            $earned = 5;
            $reason = Translator::text('Only 1 internal link; consider adding more');
        } else {
            $earned = 0;
            $reason = Translator::text('Missing internal links');
        }
        $breakdown[] = ['field' => 'internal_links', 'label' => Translator::text('Internal links'), 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 8. Alt text (5)
        $hasImages = (bool) preg_match('/!\[.*?\]\(.*?\)/', $article->bodyMarkdown);
        if (!$hasImages) {
            $earned = 5;
            $reason = Translator::text('No images; full marks by default');
        } else {
            $altText = $fm->get('alt_text');
            $altList = $this->filterNonEmptyStrings($altText);
            if (count($altList) >= 1) {
                $earned = 5;
                $reason = Translator::text('{count} image descriptions', ['count' => count($altList)]);
            } else {
                $earned = 0;
                $reason = Translator::text('Images present but no alt text provided');
            }
        }
        $breakdown[] = ['field' => 'alt_text', 'label' => Translator::text('Alt text'), 'weight' => 5, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        return new GeoScore(min(100, max(0, $total)), $breakdown);
    }

    /**
     * @return list<string>
     */
    private function filterNonEmptyStrings(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $items = is_array($value) ? $value : (is_string($value) ? [$value] : []);
        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                foreach (preg_split('/[\r\n,]+/', $item) ?: [] as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $result[] = $trimmed;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractMarkdownLinks(string $markdown, string $schemePattern): array
    {
        preg_match_all('/(?<!\!)\[(?:[^\]]+)\]\((' . $schemePattern . '[^\s)"]+)\)/i', $markdown, $matches);
        return array_values(array_unique(array_filter(array_map('trim', $matches[1] ?? []))));
    }

    /**
     * @return list<string>
     */
    private function extractBodyExternalUrls(string $markdown): array
    {
        return $this->extractMarkdownLinks($markdown, 'https?:\/\/');
    }

    /**
     * @return list<string>
     */
    private function extractBodyInternalLinks(string $markdown): array
    {
        return $this->extractMarkdownLinks($markdown, '\/');
    }
}

