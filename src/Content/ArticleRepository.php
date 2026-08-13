<?php

declare(strict_types=1);

namespace HolyMD\Content;

use InvalidArgumentException;
use RuntimeException;

final readonly class ArticleRepository
{
    public function __construct(private string $articlesRoot)
    {
    }

    public function read(string $path): ArticleDocument
    {
        $sourcePath = $this->safePath($path);
        $markdown = file_get_contents($sourcePath);
        if ($markdown === false) {
            throw new RuntimeException(sprintf('Unable to read article "%s".', $path));
        }
        [$frontMatter, $body] = FrontMatter::parse($markdown);
        $slug = $frontMatter->get('slug');
        $title = $frontMatter->get('title');
        if (!is_string($slug) || !is_string($title)) {
            throw new InvalidArgumentException('Article front matter requires title, slug, and date.');
        }
        return new ArticleDocument($slug, $title, $body, $frontMatter, $sourcePath);
    }

    public function write(ArticleDocument $document): void
    {
        $this->ensureArticlesRootExists();
        $sourcePath = $this->safePath($document->slug);
        if (file_put_contents($sourcePath, $document->serialize(), LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write article "%s".', $document->slug));
        }
    }

    public function writeIfUnchanged(ArticleDocument $document, string $expectedChecksum): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $expectedChecksum) !== 1) {
            throw new InvalidArgumentException('Article checksum must be a SHA-256 value.');
        }

        $this->ensureArticlesRootExists();
        $sourcePath = $this->safePath($document->slug);
        $handle = @fopen($sourcePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to lock article "%s".', $document->slug));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to lock article "%s".', $document->slug));
            }
            rewind($handle);
            $current = stream_get_contents($handle);
            if (!is_string($current) || !hash_equals($expectedChecksum, hash('sha256', $current))) {
                return false;
            }
            $serialized = $document->serialize();
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $serialized) !== strlen($serialized) || !fflush($handle)) {
                throw new RuntimeException(sprintf('Unable to write article "%s".', $document->slug));
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function exists(string $slug): bool
    {
        return is_file($this->safePath($slug));
    }

    public function delete(string $slug): void
    {
        $path = $this->safePath($slug);
        if (!is_file($path) || !unlink($path)) {
            throw new RuntimeException(sprintf('Unable to delete article "%s".', $slug));
        }
    }

    /** @return list<ArticleDocument> */
    public function all(): array
    {
        $documents = [];
        foreach (glob($this->articlesRoot . '/*.md') ?: [] as $path) {
            $documents[] = $this->read(basename($path, '.md'));
        }
        return $documents;
    }

    private function safePath(string $path): string
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.md)?$/', $path)) {
            throw new InvalidArgumentException('Article path must be a safe article slug.');
        }
        $slug = preg_replace('/\.md$/', '', $path);
        return $this->articlesRoot . '/' . $slug . '.md';
    }

    private function ensureArticlesRootExists(): void
    {
        if (!is_dir($this->articlesRoot) && !@mkdir($this->articlesRoot, 0775, true) && !is_dir($this->articlesRoot)) {
            throw new RuntimeException(sprintf('Unable to create articles directory "%s".', $this->articlesRoot));
        }
    }
}
