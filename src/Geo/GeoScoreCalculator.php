<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use HolyMD\Content\ArticleDocument;

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
            $reason = '已提供详实摘要（≥50字符）';
        } elseif ($summaryLen > 0) {
            $earned = 10;
            $reason = '摘要偏短（<50字符），建议充实';
        } else {
            $earned = 0;
            $reason = '缺失摘要，影响 AI/RSS 索引';
        }
        $breakdown[] = ['field' => 'summary', 'label' => '文章摘要 (Summary)', 'weight' => 20, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 2. Structured Data (20)
        $structured = $fm->get('structured_data');
        if (is_array($structured) && $structured !== []) {
            if (!empty($structured['@type']) || !empty($structured['type'])) {
                $earned = 20;
                $reason = '已配置标准 Schema.org 结构化数据';
            } else {
                $earned = 10;
                $reason = '已配置 JSON-LD 但缺少 @type 属性';
            }
        } else {
            $earned = 0;
            $reason = '缺失 JSON-LD 结构化数据';
        }
        $breakdown[] = ['field' => 'structured_data', 'label' => '结构化数据 (JSON-LD)', 'weight' => 20, 'earned' => $earned, 'reason' => $reason];
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
            $reason = sprintf('包含多组问答对（%d组）', $faqCount);
        } elseif ($faqCount === 1) {
            $earned = 8;
            $reason = '仅有 1 组问答，建议至少 2 组';
        } else {
            $earned = 0;
            $reason = '缺失 FAQ 问答候选';
        }
        $breakdown[] = ['field' => 'faq', 'label' => 'FAQ 问答对', 'weight' => 15, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 4. Entities (10)
        $entities = $fm->get('entities');
        $entityList = $this->filterNonEmptyStrings($entities);
        $entityCount = count($entityList);
        if ($entityCount >= 3) {
            $earned = 10;
            $reason = sprintf('实体关键词充分（%d个）', $entityCount);
        } elseif ($entityCount > 0) {
            $earned = 5;
            $reason = sprintf('实体数量较少（%d个，建议≥3个）', $entityCount);
        } else {
            $earned = 0;
            $reason = '缺失命名实体/关键词';
        }
        $breakdown[] = ['field' => 'entities', 'label' => '命名实体 (Entities)', 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 5. Topics (10)
        $topics = $fm->get('topics');
        $topicList = $this->filterNonEmptyStrings($topics);
        if (count($topicList) >= 1) {
            $earned = 10;
            $reason = sprintf('已关联话题分类（%s）', implode('、', $topicList));
        } else {
            $earned = 0;
            $reason = '未归类任何话题分类';
        }
        $breakdown[] = ['field' => 'topics', 'label' => '话题分类 (Topics)', 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 6. Sources (10)
        $sources = $fm->get('sources');
        $sourceList = $this->filterNonEmptyStrings($sources);
        $bodySources = $this->extractBodyExternalUrls($article->bodyMarkdown);
        $allSources = array_values(array_unique([...$sourceList, ...$bodySources]));
        $sourceCount = count($allSources);
        if ($sourceCount >= 2) {
            $earned = 10;
            $reason = sprintf('包含权威引用来源（%d条%s）', $sourceCount, $bodySources !== [] ? '，已自动识别正文引用' : '');
        } elseif ($sourceCount === 1) {
            $earned = 5;
            $reason = '仅有 1 条引用来源，建议补充';
        } else {
            $earned = 0;
            $reason = '缺失引用来源（E-E-A-T 信号不足）';
        }
        $breakdown[] = ['field' => 'sources', 'label' => '引用来源 (Sources)', 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 7. Internal links (10)
        $internalLinks = $fm->get('internal_links');
        $linkList = $this->filterNonEmptyStrings($internalLinks);
        $bodyLinks = $this->extractBodyInternalLinks($article->bodyMarkdown);
        $allLinks = array_values(array_unique([...$linkList, ...$bodyLinks]));
        $linkCount = count($allLinks);
        if ($linkCount >= 2) {
            $earned = 10;
            $reason = sprintf('包含站内互链（%d条%s）', $linkCount, $bodyLinks !== [] ? '，已自动识别正文内链' : '');
        } elseif ($linkCount === 1) {
            $earned = 5;
            $reason = '仅有 1 条站内内链，建议丰富';
        } else {
            $earned = 0;
            $reason = '缺失站内互链推荐';
        }
        $breakdown[] = ['field' => 'internal_links', 'label' => '站内互链 (Internal Links)', 'weight' => 10, 'earned' => $earned, 'reason' => $reason];
        $total += $earned;

        // 8. Alt text (5)
        $hasImages = (bool) preg_match('/!\[.*?\]\(.*?\)/', $article->bodyMarkdown);
        if (!$hasImages) {
            $earned = 5;
            $reason = '文章无配图，自动豁免满分';
        } else {
            $altText = $fm->get('alt_text');
            $altList = $this->filterNonEmptyStrings($altText);
            if (count($altList) >= 1) {
                $earned = 5;
                $reason = sprintf('已配置配图描述（%d条）', count($altList));
            } else {
                $earned = 0;
                $reason = '文章包含配图但未提供 Alt text 描述';
            }
        }
        $breakdown[] = ['field' => 'alt_text', 'label' => '图片描述 (Alt Text)', 'weight' => 5, 'earned' => $earned, 'reason' => $reason];
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

