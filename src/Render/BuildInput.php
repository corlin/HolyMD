<?php

declare(strict_types=1);

namespace HolyMD\Render;

use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;

final readonly class BuildInput
{
    /**
     * @param list<ArticleDocument> $articles
     * @param list<ArticleDocument> $pages
     */
    public function __construct(
        public array $articles,
        public PublicationSettings $settings,
        public ?string $builtAt = null,
        public array $pages = [],
    ) {}
}
