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

        $html = preg_replace_callback(
            '/<(\/?)h([1-6])(\b[^>]*)>/',
            static fn (array $match): string => '<' . $match[1] . 'h' . min(((int) $match[2]) + 1, 6) . $match[3] . '>',
            $html,
        ) ?? $html;

        return preg_replace_callback(
            '/<img\s+([^>]*?)src="(\/media\/[^"\'\s>]+\.(?:jpe?g|png))"([^>]*?)\s*\/?>/i',
            static function (array $matches): string {
                $originalSrc = $matches[2];
                $otherAttrs = trim(trim($matches[1]) . ' ' . trim($matches[3]));
                $attrs = $otherAttrs !== '' ? ' ' . $otherAttrs : '';
                $webpSrc = (string) preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $originalSrc);
                return sprintf(
                    '<picture><source srcset="%s" type="image/webp"><img src="%s"%s loading="lazy" decoding="async"></picture>',
                    $webpSrc,
                    $originalSrc,
                    $attrs
                );
            },
            $html
        ) ?? $html;
    }
}
