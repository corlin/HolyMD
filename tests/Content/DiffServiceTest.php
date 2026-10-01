<?php

declare(strict_types=1);

namespace HolyMD\Tests\Content;

use HolyMD\Content\DiffService;
use PHPUnit\Framework\TestCase;

final class DiffServiceTest extends TestCase
{
    private DiffService $diff;

    protected function setUp(): void
    {
        $this->diff = new DiffService();
    }

    public function test_diff_empty_strings(): void
    {
        $result = $this->diff->diffLines('', '');
        self::assertSame([], $result);
        self::assertFalse($this->diff->hasDiff('', ''));
    }

    public function test_diff_all_additions(): void
    {
        $result = $this->diff->diffLines('', "Line 1\nLine 2");
        self::assertCount(2, $result);
        self::assertSame('add', $result[0]['type']);
        self::assertSame('Line 1', $result[0]['content']);
        self::assertSame('add', $result[1]['type']);
        self::assertSame('Line 2', $result[1]['content']);
    }

    public function test_diff_all_deletions(): void
    {
        $result = $this->diff->diffLines("Line 1\nLine 2", '');
        self::assertCount(2, $result);
        self::assertSame('del', $result[0]['type']);
        self::assertSame('Line 1', $result[0]['content']);
        self::assertSame('del', $result[1]['type']);
        self::assertSame('Line 2', $result[1]['content']);
    }

    public function test_diff_identical_lines(): void
    {
        $text = "Line 1\nLine 2\nLine 3";
        $result = $this->diff->diffLines($text, $text);
        self::assertCount(3, $result);
        foreach ($result as $row) {
            self::assertSame('same', $row['type']);
        }
        self::assertFalse($this->diff->hasDiff($text, $text));
    }

    public function test_diff_middle_modifications_with_prefix_suffix_trimming(): void
    {
        $old = "Header\nOld Section 1\nOld Section 2\nFooter";
        $new = "Header\nNew Section 1\nFooter";

        $result = $this->diff->diffLines($old, $new);

        self::assertSame('same', $result[0]['type']);
        self::assertSame('Header', $result[0]['content']);

        self::assertSame('del', $result[1]['type']);
        self::assertSame('Old Section 1', $result[1]['content']);

        self::assertSame('del', $result[2]['type']);
        self::assertSame('Old Section 2', $result[2]['content']);

        self::assertSame('add', $result[3]['type']);
        self::assertSame('New Section 1', $result[3]['content']);

        self::assertSame('same', $result[4]['type']);
        self::assertSame('Footer', $result[4]['content']);
    }

    public function test_render_html_produces_valid_structure(): void
    {
        $old = "Hello\nWorld";
        $new = "Hello\nBrave\nWorld";

        $html = $this->diff->renderHtml($old, $new);

        self::assertStringContainsString('diff-viewer', $html);
        self::assertStringContainsString('diff-row is-same', $html);
        self::assertStringContainsString('diff-row is-add', $html);
        self::assertStringContainsString('Brave', $html);
    }
}
