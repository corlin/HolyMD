<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use DateTimeImmutable;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Render\BuildInput;
use HolyMD\Render\BuildManifest;
use HolyMD\Render\StaticBuilder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class PublishService
{
    public function __construct(
        private ArticleRepository $articles,
        private StaticBuilder $builder,
        private AtomicPublicTree $publicTree,
        private string $liveRoot,
        private string $siteName,
        private string $siteUrl,
        private string $authorName,
        private string $about,
        private bool $generateLlmsTxt = false,
        private ?string $auditRoot = null,
    ) {
    }

    public function publish(ArticleId $id): PublishResult
    {
        return $this->build($id, 'published');
    }

    public function withdraw(ArticleId $id): PublishResult
    {
        return $this->build($id, 'withdrawn');
    }

    private function build(ArticleId $id, string $nextStatus): PublishResult
    {
        $temporaryRoot = dirname($this->liveRoot) . '/.' . basename($this->liveRoot) . '-build-' . bin2hex(random_bytes(6));
        try {
            $current = $this->articles->read($id->slug);
            $updated = $current->withFrontMatter($current->frontMatter->with('status', $nextStatus));
            $documents = array_map(static fn (ArticleDocument $document): ArticleDocument => $document->slug === $id->slug ? $updated : $document, $this->articles->all());
            $published = array_values(array_filter($documents, static fn (ArticleDocument $document): bool => $document->frontMatter->get('status') === 'published'));
            $validation = $this->validate($published);
            if (!$validation->isValid()) throw new InvalidArgumentException($validation->text());
            if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) throw new RuntimeException('Unable to create temporary build directory.');
            $manifest = $this->builder->build(new BuildInput($published, $this->siteName, $this->siteUrl, $this->authorName, $this->about, $this->generateLlmsTxt), $temporaryRoot);
            $manifest = $this->writeRedirects($temporaryRoot, $published, $manifest);
            $this->writeManifest($temporaryRoot, $manifest);
            $this->publicTree->swap($temporaryRoot, $this->liveRoot);
            $this->articles->write($updated);
            $this->audit($id, $nextStatus, 'published');
            return new PublishResult($manifest, $validation);
        } catch (Throwable $exception) {
            $this->audit($id, $nextStatus, 'failed', $exception->getMessage());
            throw $exception;
        } finally {
            if (is_dir($temporaryRoot)) $this->remove($temporaryRoot);
        }
    }

    /** @param list<ArticleDocument> $published */
    private function validate(array $published): ValidationReport
    {
        $errors = [];
        $slugs = [];
        foreach ($published as $article) {
            if (isset($slugs[$article->slug])) $errors[] = sprintf('Duplicate published slug "%s".', $article->slug);
            $slugs[$article->slug] = true;
            try { new DateTimeImmutable((string) $article->frontMatter->get('date')); } catch (Throwable) { $errors[] = sprintf('Article "%s" has an invalid date.', $article->slug); }
        }
        return new ValidationReport($errors);
    }

    /** @param list<ArticleDocument> $articles */
    private function writeRedirects(string $temporaryRoot, array $articles, BuildManifest $manifest): BuildManifest
    {
        $files = $manifest->files;
        foreach ($articles as $article) {
            foreach ((array) $article->frontMatter->get('previous_slugs', []) as $oldSlug) {
                if (!is_string($oldSlug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $oldSlug) !== 1 || $oldSlug === $article->slug) continue;
                $path = $temporaryRoot . '/articles/' . $oldSlug . '/index.html';
                $target = '/articles/' . $article->slug . '/';
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) throw new RuntimeException('Unable to create redirect directory.');
                $html = '<!doctype html><meta http-equiv="refresh" content="0; url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"><link rel="canonical" href="' . htmlspecialchars(rtrim($this->siteUrl, '/') . $target, ENT_QUOTES, 'UTF-8') . '">';
                if (file_put_contents($path, $html, LOCK_EX) === false) throw new RuntimeException('Unable to write redirect.');
                $files[] = 'articles/' . $oldSlug . '/index.html';
            }
        }
        return new BuildManifest($manifest->articleCount, $files);
    }

    private function writeManifest(string $root, BuildManifest $manifest): void
    {
        $json = json_encode(['article_count' => $manifest->articleCount, 'files' => $manifest->files, 'built_at' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($root . '/.holymd-manifest.json', $json, LOCK_EX) === false) throw new RuntimeException('Unable to write build manifest.');
    }

    private function audit(ArticleId $id, string $action, string $status, ?string $error = null): void
    {
        if ($this->auditRoot === null) return;
        if (!is_dir($this->auditRoot) && !mkdir($this->auditRoot, 0775, true) && !is_dir($this->auditRoot)) return;
        file_put_contents($this->auditRoot . '/publish.jsonl', json_encode(['article_slug' => $id->slug, 'action' => $action, 'status' => $status, 'error' => $error, 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
