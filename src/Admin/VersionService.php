<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use InvalidArgumentException;
use RuntimeException;

final readonly class VersionService
{
    public function __construct(private string $versionsRoot)
    {
    }

    public function snapshot(ArticleDocument $document): VersionId
    {
        if (!is_dir($this->versionsRoot) && !mkdir($this->versionsRoot, 0775, true) && !is_dir($this->versionsRoot)) {
            throw new RuntimeException('Unable to create the article version directory.');
        }
        $id = new VersionId(bin2hex(random_bytes(16)));
        if (file_put_contents($this->path($id), $document->serialize(), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write article version snapshot.');
        }
        return $id;
    }

    public function restore(VersionId $id): ArticleDocument
    {
        $markdown = file_get_contents($this->path($id));
        if ($markdown === false) {
            throw new InvalidArgumentException('Article version was not found.');
        }
        [$frontMatter, $body] = FrontMatter::parse($markdown);
        $slug = $frontMatter->get('slug');
        $title = $frontMatter->get('title');
        if (!is_string($slug) || !is_string($title)) {
            throw new InvalidArgumentException('Article version has invalid front matter.');
        }
        return new ArticleDocument($slug, $title, $body, $frontMatter, $this->path($id));
    }

    /** @return list<VersionId> */
    public function list(): array
    {
        $files = glob($this->versionsRoot . '/*.md') ?: [];
        rsort($files, SORT_STRING);
        return array_values(array_map(static fn (string $path): VersionId => new VersionId((string) basename($path, '.md')), $files));
    }

    private function path(VersionId $id): string
    {
        return $this->versionsRoot . '/' . $id->value . '.md';
    }
}
