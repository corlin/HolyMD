<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use DateTimeImmutable;
use HolyMD\Admin\VersionService;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleMetadataValidator;
use HolyMD\Content\ArticleRepository;
use HolyMD\Config\PublicationSettings;
use HolyMD\Geo\GeoScoreCalculator;
use HolyMD\Render\BuildInput;
use HolyMD\Render\BuildManifest;
use HolyMD\Render\StaticBuilder;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use Closure;

final readonly class PublishService
{
    public function __construct(
        private ArticleRepository $articles,
        private StaticBuilder $builder,
        private AtomicPublicTree $publicTree,
        private string $liveRoot,
        private PublicationSettings $settings,
        private ?string $auditRoot = null,
        private ?Closure $persist = null,
        private ?string $lockPath = null,
        private ?VersionService $versions = null,
        private ?ArticleRepository $pages = null,
        private ?PDO $pdo = null,
        private ?GeoScoreCalculator $geoCalculator = null,
    ) {
    }

    public function rebuild(): PublishResult
    {
        $lock = $this->lock();
        $temporaryRoot = dirname($this->liveRoot) . '/.' . basename($this->liveRoot) . '-build-' . bin2hex(random_bytes(6));
        try {
            $documents = [];
            foreach ($this->articles->all() as $document) {
                if ($document->frontMatter->get('status') !== 'published') continue;
                $publishedDocument = $document;
                if ($this->versions !== null) {
                    $pointer = $document->frontMatter->get('published_version');
                    if (is_string($pointer) && preg_match('/^[a-f0-9]{32}$/', $pointer) === 1) {
                        $publishedDocument = $this->versions->restore($pointer, $document->slug);
                    }
                }
                $documents[] = $publishedDocument->withFrontMatter($publishedDocument->frontMatter->with('status', 'published'));
            }
            $published = array_values(array_filter($documents, static fn (ArticleDocument $document): bool => $document->frontMatter->get('status') === 'published'));
            $result = $this->renderSite($published, $temporaryRoot);
            $this->publicTree->swap($temporaryRoot, $this->liveRoot);
            return $result;
        } finally {
            if (is_dir($temporaryRoot)) $this->remove($temporaryRoot);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function publish(string $slug, ?string $selectedVersion = null): PublishResult
    {
        return $this->build($slug, 'published', $selectedVersion);
    }

    public function withdraw(string $slug): PublishResult
    {
        return $this->build($slug, 'withdrawn');
    }

    public function preflight(ArticleDocument $candidate): PublishPreflightResult
    {
        $documents = [];
        $currentPublic = null;
        foreach ($this->articles->all() as $document) {
            if ($document->slug === $candidate->slug) {
                if ($document->frontMatter->get('status') === 'published') {
                    $currentPublic = $this->publishedDocument($document);
                }
                $documents[] = $candidate->withFrontMatter($candidate->frontMatter->with('status', 'published'));
                continue;
            }
            if ($document->frontMatter->get('status') !== 'published') {
                continue;
            }
            $publishedDocument = $this->publishedDocument($document);
            $documents[] = $publishedDocument->withFrontMatter($publishedDocument->frontMatter->with('status', 'published'));
        }

        $validation = $this->validate($documents);
        $blockers = $validation->errors;
        $temporaryRoot = dirname($this->liveRoot) . '/.holymd-preflight-' . bin2hex(random_bytes(6));
        if ($blockers === []) {
            try {
                $this->renderSite($documents, $temporaryRoot);
            } catch (InvalidArgumentException | RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            } finally {
                if (is_dir($temporaryRoot)) {
                    $this->remove($temporaryRoot);
                }
            }
        }

        $calculator = $this->geoCalculator ?? new GeoScoreCalculator();
        $candidateScore = $calculator->calculate($candidate);
        $currentScore = $currentPublic instanceof ArticleDocument ? $calculator->calculate($currentPublic)->total : null;
        $warnings = [];
        if ($currentScore !== null && $candidateScore->total < $currentScore) {
            $warnings[] = sprintf('Candidate GEO score decreases from %d to %d.', $currentScore, $candidateScore->total);
        }
        foreach ($candidateScore->breakdown as $item) {
            if ($item['earned'] < $item['weight']) {
                $warnings[] = $item['reason'];
            }
        }

        return new PublishPreflightResult(
            hash('sha256', $candidate->serialize()),
            $currentScore,
            $candidateScore->total,
            $this->changedFields($currentPublic, $candidate),
            array_values(array_unique($blockers)),
            array_values(array_unique($warnings)),
        );
    }

    private function build(string $slug, string $nextStatus, ?string $selectedVersion = null): PublishResult
    {
        $lock = $this->lock();
        $temporaryRoot = dirname($this->liveRoot) . '/.' . basename($this->liveRoot) . '-build-' . bin2hex(random_bytes(6));
        $originals = [];
        $persisted = [];
        $versionsToConfirm = [];
        $scoreDocument = null;
        try {
            $working = $this->articles->read($slug);
            $documents = [];
            $updates = [];
            foreach ($this->articles->all() as $document) {
                if ($document->slug === $slug) {
                    if ($nextStatus === 'published') {
                        $version = $this->versions === null ? null : ($selectedVersion ?? $this->versions->capturePublicationInput($working));
                        $publicDocument = $version === null ? $working : $this->publicationInput($version, $slug);
                        if (!$publicDocument instanceof ArticleDocument) throw new RuntimeException('The selected publication version could not be restored.');
                        $scoreDocument = $publicDocument;
                        $documents[] = $publicDocument->withFrontMatter($publicDocument->frontMatter->with('status', 'published'));
                        $frontMatter = $working->frontMatter->with('status', 'published');
                        if ($version !== null) {
                            $frontMatter = $frontMatter->with('published_version', $version);
                            $versionsToConfirm[$working->slug] = $version;
                        }
                        $updates[] = $working->withFrontMatter($frontMatter);
                    } else {
                        $updates[] = $working->withFrontMatter($working->frontMatter->with('status', $nextStatus));
                    }
                    continue;
                }
                if ($document->frontMatter->get('status') !== 'published') continue;
                $publishedDocument = $document;
                if ($this->versions !== null) {
                    $pointer = $document->frontMatter->get('published_version');
                    if (is_string($pointer) && preg_match('/^[a-f0-9]{32}$/', $pointer) === 1) {
                        $publishedDocument = $this->versions->restore($pointer, $document->slug);
                    } else {
                        $version = $this->versions->capturePublicationInput($document);
                        $updates[] = $document->withFrontMatter($document->frontMatter->with('published_version', $version));
                        $versionsToConfirm[$document->slug] = $version;
                    }
                }
                $documents[] = $publishedDocument->withFrontMatter($publishedDocument->frontMatter->with('status', 'published'));
            }
            $published = array_values(array_filter($documents, static fn (ArticleDocument $document): bool => $document->frontMatter->get('status') === 'published'));
            $result = $this->renderSite($published, $temporaryRoot);
            if ($this->versions !== null) foreach ($versionsToConfirm as $version) $this->versions->stagePublished($version);
            foreach ($updates as $updated) {
                $originals[$updated->slug] = $this->articles->read($updated->slug);
                $this->persist($updated);
                $persisted[] = $updated->slug;
            }
            $this->publicTree->swap($temporaryRoot, $this->liveRoot);
            if ($this->versions !== null) foreach ($versionsToConfirm as $articleSlug => $version) $this->versions->confirmPublished($articleSlug, $version);
            $this->audit($slug, $nextStatus, 'published');
            if ($nextStatus === 'published' && $scoreDocument instanceof ArticleDocument) {
                $this->recordGeoScore($scoreDocument);
            }
            return $result;
        } catch (Throwable $exception) {
            foreach (array_reverse($persisted) as $itemSlug) {
                try { $this->persist($originals[$itemSlug]); } catch (Throwable) { /* audit retains the failed recovery context */ }
            }
            $this->audit($slug, $nextStatus, 'failed', $exception->getMessage());
            throw $exception;
        } finally {
            if (is_dir($temporaryRoot)) $this->remove($temporaryRoot);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function publicationInput(string $version, string $slug): ArticleDocument
    {
        if ($this->versions === null) throw new RuntimeException('Article version storage is not configured.');
        try {
            return $this->versions->restorePublicationInput($version, $slug);
        } catch (InvalidArgumentException) {
            return $this->versions->restore($version, $slug);
        }
    }

    private function publishedDocument(ArticleDocument $document): ArticleDocument
    {
        if ($this->versions === null) {
            return $document;
        }
        $pointer = $document->frontMatter->get('published_version');
        if (!is_string($pointer) || preg_match('/^[a-f0-9]{32}$/', $pointer) !== 1) {
            return $document;
        }
        return $this->versions->restore($pointer, $document->slug);
    }

    /** @return list<string> */
    private function changedFields(?ArticleDocument $current, ArticleDocument $candidate): array
    {
        if ($current === null) {
            return ['new publication'];
        }
        $changes = [];
        if ($current->title !== $candidate->title) $changes[] = 'title';
        if ((string) $current->frontMatter->get('date') !== (string) $candidate->frontMatter->get('date')) $changes[] = 'date';
        if ($current->bodyMarkdown !== $candidate->bodyMarkdown) $changes[] = 'body';
        foreach (['summary', 'topics', 'entities', 'faq', 'sources', 'alt_text', 'hierarchy', 'internal_links', 'previous_slugs', 'structured_data'] as $field) {
            if ($current->frontMatter->get($field) !== $candidate->frontMatter->get($field)) {
                $changes[] = $field;
            }
        }
        return $changes;
    }

    /** @param list<ArticleDocument> $published */
    private function renderSite(array $published, string $temporaryRoot): PublishResult
    {
        $validation = $this->validate($published);
        if (!$validation->isValid()) throw new InvalidArgumentException($validation->text());
        if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) throw new RuntimeException('Unable to create temporary build directory.');
        $pages = $this->pages?->all() ?? [];
        $manifest = $this->builder->build(new BuildInput($published, $this->settings, pages: $pages), $temporaryRoot);
        $manifest = $this->writeRedirects($temporaryRoot, $published, $manifest);
        $this->writeManifest($temporaryRoot, $manifest);
        return new PublishResult($manifest, $validation);
    }

    /** @param list<ArticleDocument> $published */
    private function validate(array $published): ValidationReport
    {
        $errors = $this->settings->validationErrors();
        $slugs = [];
        $redirects = [];
        foreach ($published as $article) {
            if (isset($slugs[$article->slug])) $errors[] = sprintf('Duplicate published slug "%s".', $article->slug);
            $slugs[$article->slug] = true;
            $errors = [...$errors, ...ArticleMetadataValidator::errors($article)];
            foreach ((array) $article->frontMatter->get('previous_slugs', []) as $oldSlug) {
                if (!is_string($oldSlug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $oldSlug) !== 1) continue;
                if (isset($slugs[$oldSlug]) || isset($redirects[$oldSlug])) $errors[] = sprintf('Redirect slug "%s" collides with a published route.', $oldSlug);
                $redirects[$oldSlug] = true;
            }
        }
        foreach ($redirects as $slug => $_) if (isset($slugs[$slug])) $errors[] = sprintf('Redirect slug "%s" collides with a published route.', $slug);
        return new ValidationReport($errors);
    }

    /** @param list<ArticleDocument> $articles */
    private function writeRedirects(string $temporaryRoot, array $articles, BuildManifest $manifest): BuildManifest
    {
        $files = $manifest->files;
        $redirects = [];
        foreach ($articles as $article) {
            foreach ((array) $article->frontMatter->get('previous_slugs', []) as $oldSlug) {
                if (!is_string($oldSlug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $oldSlug) !== 1 || $oldSlug === $article->slug) continue;
                $path = $temporaryRoot . '/articles/' . $oldSlug . '/index.html';
                $target = '/articles/' . $article->slug . '/';
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) throw new RuntimeException('Unable to create redirect directory.');
                $html = '<!doctype html><meta http-equiv="refresh" content="0; url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"><link rel="canonical" href="' . htmlspecialchars(rtrim($this->settings->siteUrl, '/') . $target, ENT_QUOTES, 'UTF-8') . '">';
                if (file_put_contents($path, $html, LOCK_EX) === false) throw new RuntimeException('Unable to write redirect.');
                $files[] = 'articles/' . $oldSlug . '/index.html';
                // 301 map consumed by the PHP router; the meta-refresh page above
                // stays as the fallback for non-PHP serving.
                $redirects[$oldSlug . '/'] = $target;
            }
        }
        if ($redirects !== []) {
            if (file_put_contents($temporaryRoot . '/.holymd-redirects.json', json_encode($redirects, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) throw new RuntimeException('Unable to write redirect manifest.');
            $files[] = '.holymd-redirects.json';
        }
        return new BuildManifest($manifest->articleCount, $files);
    }

    private function writeManifest(string $root, BuildManifest $manifest): void
    {
        $json = json_encode(['article_count' => $manifest->articleCount, 'files' => $manifest->files, 'built_at' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($root . '/.holymd-manifest.json', $json, LOCK_EX) === false) throw new RuntimeException('Unable to write build manifest.');
    }

    private function audit(string $slug, string $action, string $status, ?string $error = null): void
    {
        if ($this->auditRoot === null) return;
        if (!is_dir($this->auditRoot) && !mkdir($this->auditRoot, 0775, true) && !is_dir($this->auditRoot)) return;
        file_put_contents($this->auditRoot . '/publish.jsonl', json_encode(['article_slug' => $slug, 'action' => $action, 'status' => $status, 'error' => $error, 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
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

    private function persist(ArticleDocument $document): void
    {
        if ($this->persist !== null) { ($this->persist)($document); return; }
        $this->articles->write($document);
    }

    /** @return resource */
    private function lock()
    {
        $path = $this->lockPath ?? dirname($this->liveRoot) . '/.holymd-publish.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('A publication is already running.');
        return $handle;
    }

    private function recordGeoScore(ArticleDocument $article): void
    {
        if ($this->pdo === null) {
            return;
        }
        try {
            $calculator = $this->geoCalculator ?? new GeoScoreCalculator();
            $score = $calculator->calculate($article);
            $stmt = $this->pdo->prepare("INSERT INTO geo_scores (slug, score, breakdown, snapshot_trigger) VALUES (:slug, :score, :breakdown, 'publish')");
            $stmt->execute([
                ':slug' => $article->slug,
                ':score' => $score->total,
                ':breakdown' => json_encode($score->breakdown, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // The public tree is already live, so this secondary failure is
            // observable and repairable without pretending publication rolled back.
            $this->audit($article->slug, 'geo-score', 'failed', $exception->getMessage());
        }
    }
}
