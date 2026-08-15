<?php

declare(strict_types=1);

namespace HolyMD\Render;

use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;

final readonly class BuildInput
{
    public string $siteName;
    public string $siteUrl;
    public string $authorName;
    public string $about;
    public bool $generateLlmsTxt;
    public string $siteLanguage;
    public string $basePath;

    /**
     * @param list<ArticleDocument> $articles
     * @param list<ArticleDocument> $pages
     */
    public function __construct(
        public array $articles,
        public PublicationSettings $settings,
        public ?string $builtAt = null,
        public array $pages = [],
    ) {
        $this->siteName = $settings->siteName;
        $this->siteUrl = $settings->siteUrl;
        $this->authorName = $settings->authorName;
        $this->about = $settings->about;
        $this->generateLlmsTxt = $settings->generateLlmsTxt;
        $this->siteLanguage = $settings->siteLanguage;
        $this->basePath = $settings->basePath;
    }
}
