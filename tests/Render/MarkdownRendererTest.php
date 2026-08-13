<?php

declare(strict_types=1);

namespace HolyMD\Tests\Render;

use HolyMD\Render\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function test_renders_commonmark_and_gfm_with_article_heading_normalization(): void
    {
        $markdown = <<<'MARKDOWN'
# Section

> A **strong** quote with *emphasis* and [source](https://example.org).

1. First
2. Second

| Name | Kind |
| --- | --- |
| HolyMD | Tool |

```php
echo "safe";
```
MARKDOWN;

        $html = (new MarkdownRenderer())->render($markdown);

        self::assertStringContainsString('<h2>Section</h2>', $html);
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<strong>strong</strong>', $html);
        self::assertStringContainsString('<em>emphasis</em>', $html);
        self::assertStringContainsString('<a href="https://example.org">source</a>', $html);
        self::assertStringContainsString('<ol>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<pre><code class="language-php">', $html);
    }

    public function test_strips_raw_html_and_disallows_unsafe_links(): void
    {
        $html = (new MarkdownRenderer())->render("<script>alert(1)</script>\n\n[bad](javascript:alert(1))");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('bad', $html);
    }

    public function test_commonmark_blocks_have_readable_preview_and_public_styles(): void
    {
        $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $public = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/site.css');

        foreach (['.prose blockquote', '.prose table', '.prose img', '.prose hr', '.prose a'] as $selector) {
            self::assertStringContainsString($selector, $admin);
            self::assertStringContainsString($selector, $public);
        }
    }

    public function test_admin_mobile_navigation_releases_sticky_viewport_height(): void
    {
        $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertMatchesRegularExpression(
            '/@media\(max-width:650px\).*?\.left-rail\{[^}]*position:static;[^}]*height:auto;[^}]*max-height:none;/s',
            $admin,
        );
    }
}
