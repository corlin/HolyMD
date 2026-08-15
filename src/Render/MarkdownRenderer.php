<?php

declare(strict_types=1);

namespace HolyMD\Render;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class MarkdownRenderer
{
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'max_delimiters_per_line' => 1000,
        ]);
    }

    public function render(string $markdown): string
    {
        $html = (string) $this->converter->convert($markdown);

        return preg_replace_callback(
            '/<(\/?)h([1-6])(\b[^>]*)>/',
            static fn (array $match): string => '<' . $match[1] . 'h' . min(((int) $match[2]) + 1, 6) . $match[3] . '>',
            $html,
        ) ?? $html;
    }
}
