<?php

declare(strict_types=1);

namespace HolyMD\Content;

use InvalidArgumentException;

final readonly class ArticleDocument
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $bodyMarkdown,
        public FrontMatter $frontMatter,
        public string $sourcePath,
    ) {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException('Article slug must be a lowercase URL-safe slug.');
        }
        if ($title === '' || $frontMatter->get('date') === null || $frontMatter->get('date') === '') {
            throw new InvalidArgumentException('Article front matter requires title, slug, and date.');
        }
    }

    public function withFrontMatter(FrontMatter $frontMatter): self
    {
        return new self($this->slug, $this->title, $this->bodyMarkdown, $frontMatter, $this->sourcePath);
    }

    public function serialize(): string
    {
        return "---\n" . $this->frontMatter->toYaml() . "\n---\n" . $this->bodyMarkdown;
    }
}
