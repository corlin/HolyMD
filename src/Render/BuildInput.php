<?php

declare(strict_types=1);

namespace HolyMD\Render;

use HolyMD\Content\ArticleDocument;

final readonly class BuildInput
{
    /** @param list<ArticleDocument> $articles */
    public function __construct(
        public array $articles,
        public string $siteName,
        public string $siteUrl,
        public string $authorName,
        public string $about,
        public bool $generateLlmsTxt = false,
        public string $siteLanguage = 'zh-CN',
        public ?string $builtAt = null,
        public string $basePath = '',
    ) {
    }
}
