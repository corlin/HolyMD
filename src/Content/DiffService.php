<?php

declare(strict_types=1);

namespace HolyMD\Content;

final class DiffService
{
    /**
     * Calculate line-by-line diff between two strings.
     *
     * @return list<array{type: 'same'|'add'|'del', content: string}>
     */
    public function diffLines(string $oldText, string $newText): array
    {
        $oldLines = $oldText === '' ? [] : explode("\n", $oldText);
        $newLines = $newText === '' ? [] : explode("\n", $newText);

        $oldCount = count($oldLines);
        $newCount = count($newLines);

        // Fast paths
        if ($oldCount === 0 && $newCount === 0) {
            return [];
        }
        if ($oldCount === 0) {
            return array_map(static fn (string $line): array => ['type' => 'add', 'content' => $line], $newLines);
        }
        if ($newCount === 0) {
            return array_map(static fn (string $line): array => ['type' => 'del', 'content' => $line], $oldLines);
        }

        // Prefix trimming
        $prefix = [];
        $i = 0;
        while ($i < $oldCount && $i < $newCount && $oldLines[$i] === $newLines[$i]) {
            $prefix[] = ['type' => 'same', 'content' => $oldLines[$i]];
            $i++;
        }

        // Suffix trimming
        $suffix = [];
        $oldEnd = $oldCount - 1;
        $newEnd = $newCount - 1;
        while ($oldEnd >= $i && $newEnd >= $i && $oldLines[$oldEnd] === $newLines[$newEnd]) {
            array_unshift($suffix, ['type' => 'same', 'content' => $oldLines[$oldEnd]]);
            $oldEnd--;
            $newEnd--;
        }

        $trimmedOld = array_slice($oldLines, $i, $oldEnd - $i + 1);
        $trimmedNew = array_slice($newLines, $i, $newEnd - $i + 1);

        $middle = [];
        if ($trimmedOld === []) {
            $middle = array_map(static fn (string $line): array => ['type' => 'add', 'content' => $line], $trimmedNew);
        } elseif ($trimmedNew === []) {
            $middle = array_map(static fn (string $line): array => ['type' => 'del', 'content' => $line], $trimmedOld);
        } else {
            $matrix = $this->buildLcsMatrix($trimmedOld, $trimmedNew);
            $middle = $this->backtrack($matrix, $trimmedOld, $trimmedNew, count($trimmedOld), count($trimmedNew));
        }

        return [...$prefix, ...$middle, ...$suffix];
    }

    /**
     * Render line diff as an accessible HTML block.
     */
    public function renderHtml(string $oldText, string $newText): string
    {
        $diff = $this->diffLines($oldText, $newText);
        if ($diff === []) {
            return '<div class="diff-viewer is-empty"><p class="muted">No content changes</p></div>';
        }

        $hasChanges = false;
        $html = '<div class="diff-viewer" role="region" aria-label="Content differences">';
        $html .= '<table class="diff-table"><tbody>';

        foreach ($diff as $item) {
            $type = $item['type'];
            if ($type !== 'same') {
                $hasChanges = true;
            }
            $escaped = htmlspecialchars($item['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sign = match ($type) {
                'add' => '+',
                'del' => '−',
                'same' => ' ',
            };
            $class = match ($type) {
                'add' => 'diff-row is-add',
                'del' => 'diff-row is-del',
                'same' => 'diff-row is-same',
            };
            $html .= sprintf(
                '<tr class="%s"><td class="diff-sign" aria-hidden="true">%s</td><td class="diff-code"><code>%s</code></td></tr>',
                $class,
                $sign,
                $escaped !== '' ? $escaped : '&nbsp;'
            );
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    /**
     * Check if two texts have differences.
     */
    public function hasDiff(string $oldText, string $newText): bool
    {
        return $oldText !== $newText;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return array<int, array<int, int>>
     */
    private function buildLcsMatrix(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        $matrix = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $matrix[$i][$j] = $matrix[$i - 1][$j - 1] + 1;
                } else {
                    $matrix[$i][$j] = max($matrix[$i - 1][$j], $matrix[$i][$j - 1]);
                }
            }
        }

        return $matrix;
    }

    /**
     * @param array<int, array<int, int>> $matrix
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{type: 'same'|'add'|'del', content: string}>
     */
    private function backtrack(array $matrix, array $a, array $b, int $i, int $j): array
    {
        $result = [];
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $result[] = ['type' => 'same', 'content' => $a[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
                $result[] = ['type' => 'add', 'content' => $b[$j - 1]];
                $j--;
            } elseif ($i > 0 && ($j === 0 || $matrix[$i][$j - 1] < $matrix[$i - 1][$j])) {
                $result[] = ['type' => 'del', 'content' => $a[$i - 1]];
                $i--;
            }
        }

        return array_reverse($result);
    }
}
