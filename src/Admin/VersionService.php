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
        $serialized = $document->serialize();
        $id = new VersionId(substr(hash('sha256', $serialized), 0, 32));
        if (!is_file($this->path($id)) && file_put_contents($this->path($id), $serialized, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write article version snapshot.');
        }
        $this->recordIndex($document->slug, $id->value);
        return $id;
    }

    public function restore(VersionId $id, ?string $expectedSlug = null): ArticleDocument
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
        $document = new ArticleDocument($slug, $title, $body, $frontMatter, $this->path($id));
        if ($expectedSlug !== null && $document->slug !== $expectedSlug) {
            throw new InvalidArgumentException('Article version does not belong to this article.');
        }
        return $document;
    }

    /** @return list<VersionId> */
    public function list(string $articleSlug): array
    {
        $indexed = $this->indexedIds($articleSlug);
        if ($indexed !== null) {
            return $indexed;
        }
        // Fallback for snapshots taken before the index existed: full scan.
        $files = glob($this->versionsRoot . '/*.md') ?: [];
        rsort($files, SORT_STRING);
        return array_values(array_filter(array_map(
            function (string $path) use ($articleSlug): ?VersionId {
                $id = new VersionId((string) basename($path, '.md'));
                try {
                    $this->restore($id, $articleSlug);
                    return $id;
                } catch (InvalidArgumentException) {
                    return null;
                }
            },
            $files,
        )));
    }

    /** @return ?list<VersionId> */
    private function indexedIds(string $articleSlug): ?array
    {
        $raw = is_file($this->indexPath()) ? file_get_contents($this->indexPath()) : false;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $index = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $ids = $index[$articleSlug] ?? null;
        if (!is_array($ids)) {
            return null;
        }
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1));
        $ids = array_values(array_filter($ids, fn (string $id): bool => is_file($this->path(new VersionId($id)))));
        rsort($ids, SORT_STRING);
        return array_map(static fn (string $id): VersionId => new VersionId($id), $ids);
    }

    private function recordIndex(string $slug, string $versionId): void
    {
        $lock = fopen($this->versionsRoot . '/index.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            return;
        }
        try {
            $raw = is_file($this->indexPath()) ? file_get_contents($this->indexPath()) : false;
            $index = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $index = $decoded;
            }
            if (!isset($index[$slug]) || !is_array($index[$slug])) $index[$slug] = [];
            if (!in_array($versionId, $index[$slug], true)) $index[$slug][] = $versionId;
            $temporary = $this->versionsRoot . '/index.json.tmp';
            if (file_put_contents($temporary, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
                rename($temporary, $this->indexPath());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function indexPath(): string
    {
        return $this->versionsRoot . '/index.json';
    }

    private function path(VersionId $id): string
    {
        return $this->versionsRoot . '/' . $id->value . '.md';
    }
}
