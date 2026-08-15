<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use InvalidArgumentException;
use RuntimeException;

final readonly class VersionService
{
    public function __construct(private string $versionsRoot)
    {
    }

    private function recordPublishedSnapshot(ArticleDocument $document): string
    {
        $id = $this->writeSnapshot($document, $this->versionsRoot);
        $this->recordIndex($document->slug, $id);
        return $id;
    }

    public function captureReviewInput(ArticleDocument $document): string
    {
        return $this->writeSnapshot($document, $this->reviewInputsRoot());
    }

    public function capturePublicationInput(ArticleDocument $document): string
    {
        return $this->writeSnapshot($document, $this->publicationInputsRoot());
    }

    public function restore(string $id, ?string $expectedSlug = null): ArticleDocument
    {
        return $this->restoreFromPath($id, $this->path($id), $expectedSlug);
    }

    public function restoreReviewInput(string $id, ?string $expectedSlug = null): ArticleDocument
    {
        return $this->restoreFromPath($id, $this->reviewInputPath($id), $expectedSlug);
    }

    public function restorePublicationInput(string $id, ?string $expectedSlug = null): ArticleDocument
    {
        return $this->restoreFromPath($id, $this->publicationInputPath($id), $expectedSlug);
    }

    public function stagePublished(string $id): void
    {
        if (is_file($this->path($id))) return;
        $source = $this->publicationInputPath($id);
        if (!is_file($source)) throw new InvalidArgumentException('Publication input snapshot was not found.');
        if (!is_dir($this->versionsRoot) && !mkdir($this->versionsRoot, 0775, true) && !is_dir($this->versionsRoot)) {
            throw new RuntimeException('Unable to create the article version directory.');
        }
        if (!copy($source, $this->path($id))) throw new RuntimeException('Unable to stage the published article version.');
    }

    public function confirmPublished(string $articleSlug, string $id): void
    {
        $this->restore($id, $articleSlug);
        $this->recordIndex($articleSlug, $id);
    }

    private function restoreFromPath(string $id, string $path, ?string $expectedSlug): ArticleDocument
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Article snapshot was not found.');
        }
        $markdown = file_get_contents($path);
        if ($markdown === false) {
            throw new InvalidArgumentException('Article snapshot was not found.');
        }
        [$frontMatter, $body] = FrontMatter::parse($markdown);
        $slug = $frontMatter->get('slug');
        $title = $frontMatter->get('title');
        if (!is_string($slug) || !is_string($title)) {
            throw new InvalidArgumentException('Article version has invalid front matter.');
        }
        $document = new ArticleDocument($slug, $title, $body, $frontMatter, $path);
        if ($expectedSlug !== null && $document->slug !== $expectedSlug) {
            throw new InvalidArgumentException('Article version does not belong to this article.');
        }
        return $document;
    }

    private function writeSnapshot(ArticleDocument $document, string $directory): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the article snapshot directory.');
        }
        $serialized = $document->serialize();
        $id = substr(hash('sha256', $serialized), 0, 32);
        $path = $directory . '/' . $id . '.md';
        if (!is_file($path) && file_put_contents($path, $serialized, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write article snapshot.');
        }
        return $id;
    }

    public function pinPublished(ArticleRepository $articles): int
    {
        $pinned = 0;
        foreach ($articles->all() as $document) {
            if ($document->frontMatter->get('status') !== 'published') continue;
            $pointer = $document->frontMatter->get('published_version');
            if (is_string($pointer) && preg_match('/^[a-f0-9]{32}$/', $pointer) === 1) {
                try {
                    $this->restore($pointer, $document->slug);
                    continue;
                } catch (InvalidArgumentException) {
                    // Re-pin a missing or invalid legacy pointer below.
                }
            }
            $version = $this->recordPublishedSnapshot($document);
            $articles->write($document->withFrontMatter($document->frontMatter->with('published_version', $version)));
            $pinned++;
        }
        return $pinned;
    }

    /** @return list<string> */
    public function list(string $articleSlug): array
    {
        $indexed = $this->indexedIds($articleSlug);
        return $indexed ?? [];
    }

    /** @return ?list<string> */
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
        $ids = array_values(array_filter($ids, fn (string $id): bool => is_file($this->path($id))));
        rsort($ids, SORT_STRING);
        return $ids;
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

    private function path(string $id): string
    {
        return $this->versionsRoot . '/' . $id . '.md';
    }

    private function reviewInputsRoot(): string
    {
        return $this->versionsRoot . '/review-inputs';
    }

    private function reviewInputPath(string $id): string
    {
        return $this->reviewInputsRoot() . '/' . $id . '.md';
    }

    private function publicationInputsRoot(): string
    {
        return $this->versionsRoot . '/publish-inputs';
    }

    private function publicationInputPath(string $id): string
    {
        return $this->publicationInputsRoot() . '/' . $id . '.md';
    }
}
