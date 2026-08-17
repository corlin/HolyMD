<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\Unauthorized;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleMetadataValidator;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\GeoAutoMerge;
use HolyMD\Geo\GeoConfiguration;
use HolyMD\Geo\GeoScoreCalculator;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use HolyMD\Publish\PublishService;
use HolyMD\Publish\PublishPreflightResult;
use HolyMD\Queue\MySqlJobQueue;
use HolyMD\Render\MarkdownRenderer;
use InvalidArgumentException;

final readonly class ArticleController
{
    use AdminAuthorizationTrait;

    public function __construct(
        private ArticleRepository $articles,
        private VersionService $versions,
        private AdminGuard $guard,
        private Csrf $csrf,
        private ?PublishService $publisher = null,
        private ?MySqlJobQueue $queue = null,
        private ?string $mediaRoot = null,
        /** @var array<string, string> */
        private array $siteSettings = [],
        private ?MarkdownRenderer $markdownRenderer = null,
        private ?GeoScoreCalculator $geoCalculator = null,
    ) {
    }

    public function previewMarkdown(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        $body = $request->input('body');
        if (!is_string($body) || strlen($body) > 1024 * 1024) {
            return Response::json(['error' => 'Markdown preview requires a body no larger than 1 MB.'], 422);
        }

        return Response::json(['html' => ($this->markdownRenderer ?? new MarkdownRenderer())->render($body)]);
    }

    public function saveDraft(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        try {
            $slug = $this->slugFromPath($request->path);
            $existing = $this->articles->read($slug);
            $document = $this->submittedArticle($request, $existing);
            $expectedChecksum = $request->input('expected_checksum');
            if (!is_string($expectedChecksum) || !$this->articles->writeIfUnchanged($document, $expectedChecksum)) {
                return Response::json(['error' => 'The article changed in another editor session. Reload before saving again.'], 409);
            }
            return Response::json(['saved' => true, 'checksum' => hash('sha256', $document->serialize())]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function index(ServerRequest $request): Response
    {
        try {
            $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }
        $articles = $this->articles->all();
        $calculator = $this->geoCalculator ?? new GeoScoreCalculator();
        $geoScores = [];
        foreach ($articles as $article) {
            $geoScores[$article->slug] = $calculator->calculate($article);
        }
        $csrfToken = $this->csrf->token();
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/articles/index.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function new(ServerRequest $request): Response
    {
        try {
            $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }
        $csrfToken = $this->csrf->token();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/articles/new.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function create(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        try {
            $title = $request->input('title');
            $date = $request->input('date');
            $body = $request->input('body', '');
            $submittedSlug = $request->input('slug', '');
            if (!is_string($title) || trim($title) === '' || !is_string($date) || !is_string($body) || !is_string($submittedSlug)) {
                throw new InvalidArgumentException('Article title, date, and Markdown body are required.');
            }
            $title = trim($title);
            $slug = $this->safeSlug($submittedSlug !== '' ? $submittedSlug : $title);
            if ($this->articles->exists($slug)) {
                throw new InvalidArgumentException('An article with this slug already exists.');
            }
            $frontMatter = $this->applyMetadataInputs($request, new FrontMatter(['title' => $title, 'slug' => $slug, 'date' => $date]));
            $document = new ArticleDocument($slug, $title, $body, $frontMatter, $slug . '.md');
            $this->assertValidMetadata($document);
            $this->articles->write($document);
            return Response::redirect('/admin/articles/' . rawurlencode($slug) . '/edit');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function edit(ServerRequest $request): Response
    {
        try {
            $this->guard->requireAdministrator();
            if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/edit$#', $request->path, $matches) !== 1) {
                throw new InvalidArgumentException('Invalid article edit route.');
            }
            $article = $this->articles->read($matches[1]);
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'Article not found.'], 404);
        }
        $versions = $this->versions->list($article->slug);
        $articleChecksum = $this->sourceChecksum($article);
        $calculator = $this->geoCalculator ?? new GeoScoreCalculator();
        $geoScore = $calculator->calculate($article);
        $csrfToken = $this->csrf->token();
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/articles/edit.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function restore(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        try {
            if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/restore/([a-f0-9]{32})$#', $request->path, $matches) !== 1) {
                throw new InvalidArgumentException('Invalid article restore route.');
            }
            $version = $matches[2];
            $document = $this->versions->restore($version, $matches[1]);
            $this->articles->write($document);
            return Response::redirect('/admin/articles/' . $document->slug . '/edit');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function preflight(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $this->publicationError('Publication preflight was rejected.', $response->status);
        }
        if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/preflight$#', $request->path, $matches) !== 1) {
            return $this->publicationError('Invalid publication preflight route.', 422);
        }
        if ($this->publisher === null) {
            return $this->publicationError('Publishing is not configured.', 503, $matches[1]);
        }

        try {
            $article = $this->articles->read($matches[1]);
            $expectedChecksum = $request->input('expected_checksum');
            if (!is_string($expectedChecksum) || !hash_equals($this->sourceChecksum($article), $expectedChecksum)) {
                return $this->publicationError('The article changed in another editor session. Reload before publishing.', 409, $matches[1]);
            }
            $candidate = $this->submittedArticle($request, $article);
            return $this->preflightResponse($candidate, $this->publisher->preflight($candidate), $expectedChecksum);
        } catch (InvalidArgumentException $exception) {
            return $this->publicationError($exception->getMessage(), 422, $matches[1]);
        } catch (\RuntimeException $exception) {
            return $this->publicationError($exception->getMessage(), 500, $matches[1]);
        }
    }

    public function publish(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            $payload = json_decode($response->body, true);
            return $this->publicationError(is_array($payload) && is_string($payload['error'] ?? null) ? $payload['error'] : 'Publication request was rejected.', $response->status);
        }
        if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/(publish|withdraw)$#', $request->path, $matches) !== 1) {
            return $this->publicationError('Invalid publication route.', 422);
        }
        if ($this->publisher === null) {
            return $this->publicationError('Publishing is not configured.', 503, $matches[1]);
        }
        try {
            $article = $this->articles->read($matches[1]);
            $selectedVersion = null;
            if ($matches[2] === 'publish') {
                $hasSubmittedBody = $request->input('body') !== null;
                $updated = $hasSubmittedBody ? $this->submittedArticle($request, $article) : $article;
                $expectedChecksum = $request->input('expected_checksum');
                if ($hasSubmittedBody && (!is_string($expectedChecksum) || !hash_equals($this->sourceChecksum($article), $expectedChecksum))) {
                    return $this->publicationError('The article changed in another editor session. Reload before publishing.', 409, $matches[1]);
                }
                $preflight = $this->publisher->preflight($updated);
                if (!$preflight->canPublish()) {
                    return $this->publicationError(implode("\n", $preflight->blockers), 422, $matches[1]);
                }
                if ($preflight->requiresAcknowledgement() && !hash_equals($preflight->checksum, (string) $request->input('preflight_acknowledgement', ''))) {
                    return $this->publicationError('A preflight acknowledgement bound to the current article is required.', 409, $matches[1]);
                }
                if ($hasSubmittedBody) {
                    if (!$this->articles->writeIfUnchanged($updated, (string) $expectedChecksum)) {
                        return $this->publicationError('The article changed in another editor session. Reload before publishing.', 409, $matches[1]);
                    }
                    $selectedVersion = $this->versions->capturePublicationInput($updated);
                    $article = $updated;
                }
            }
            if ($matches[2] === 'publish' && $selectedVersion === null) $selectedVersion = $this->versions->capturePublicationInput($article);
            if ($this->queue !== null) {
                $jobId = $this->queue->enqueueBuild($article, $matches[2], $selectedVersion === null ? null : 'publish-inputs/' . $selectedVersion . '.md');
                return $this->publicationResponse($matches[1], $matches[2], true, $jobId);
            }
            $result = $matches[2] === 'publish' ? $this->publisher->publish($matches[1], $selectedVersion) : $this->publisher->withdraw($matches[1]);
            return $this->publicationResponse($matches[1], $matches[2]);
        } catch (InvalidArgumentException $exception) {
            return $this->publicationError($exception->getMessage(), 422, $matches[1]);
        } catch (\RuntimeException $exception) {
            return $this->publicationError($exception->getMessage(), 500, $matches[1]);
        }
    }

    private function preflightResponse(ArticleDocument $candidate, PublishPreflightResult $preflight, string $expectedChecksum): Response
    {
        $csrfToken = $this->csrf->token();
        $fields = $this->publicationFields($candidate);
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/articles/preflight.php';
        return new Response($preflight->canPublish() ? 200 : 422, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return array<string, string> */
    private function publicationFields(ArticleDocument $document): array
    {
        $fields = [
            'title' => $document->title,
            'date' => (string) $document->frontMatter->get('date'),
            'body' => $document->bodyMarkdown,
        ];
        foreach (['summary', 'topics', 'entities', 'faq', 'sources', 'alt_text', 'hierarchy', 'internal_links', 'previous_slugs', 'structured_data'] as $key) {
            $value = $document->frontMatter->get($key);
            if ($value === null) {
                $fields[$key] = '';
            } elseif (is_array($value) && array_is_list($value) && array_reduce($value, static fn (bool $ok, mixed $item): bool => $ok && is_string($item), true)) {
                $fields[$key] = implode("\n", $value);
            } elseif (is_array($value)) {
                $fields[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } else {
                $fields[$key] = (string) $value;
            }
        }
        return $fields;
    }

    private function sourceChecksum(ArticleDocument $document): string
    {
        $checksum = hash_file('sha256', $document->sourcePath);
        if (!is_string($checksum)) {
            throw new \RuntimeException('Unable to checksum the current article source.');
        }
        return $checksum;
    }

    private function publicationError(string $message, int $status, ?string $slug = null): Response
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $back = $slug === null ? '/admin/articles' : '/admin/articles/' . rawurlencode($slug) . '/edit';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Publication failed</title><link rel="stylesheet" href="/assets/admin.css"></head><body><main class="result-page"><h1>Publication failed</h1><p role="alert">' . $escape($message) . '</p><p><a href="' . $escape($back) . '">Return to editor</a></p></main></body></html>';
        return new Response($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function publicationResponse(string $slug, string $action, bool $queued = false, ?int $jobId = null): Response
    {
        $message = $queued ? 'Publication queued.' : ($action === 'publish' ? 'Article published.' : 'Article withdrawn.');
        $detail = $queued ? '<p>The Cron worker will process job ' . (int) $jobId . '. Return later to view the result.</p>' : '';
        $publicLink = !$queued && $action === 'publish' ? '<p><a href="/articles/' . rawurlencode($slug) . '/">View public article</a></p>' : '';
        return new Response($queued ? 202 : 200, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $message . '</title><link rel="stylesheet" href="/assets/admin.css"></head><body><main class="result-page"><h1>' . $message . '</h1>' . $detail . $publicLink . '<p><a href="/admin/articles/' . rawurlencode($slug) . '/edit">Return to editor</a></p></main></body></html>', ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function delete(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) return $response;
        if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/delete$#', $request->path, $matches) !== 1) return Response::json(['error' => 'Invalid delete route.'], 422);
        try {
            $article = $this->articles->read($matches[1]);
            if ($article->frontMatter->get('status', 'draft') !== 'draft') throw new InvalidArgumentException('Only draft articles can be deleted. Withdraw a published article first.');
            if ($request->input('confirm_slug') !== $article->slug) throw new InvalidArgumentException('Type the article slug to confirm deletion.');
            $this->articles->delete($article->slug);
            $this->versions->purge($article->slug);
            return Response::redirect('/admin/articles');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function media(ServerRequest $request): Response
    {
        try { $this->guard->requireAdministrator(); } catch (Unauthorized) { return Response::json(['error' => 'Administrator authentication is required.'], 401); }
        $media = $this->mediaFiles();
        $csrfToken = $this->csrf->token();
        ob_start(); require dirname(__DIR__, 2) . '/templates/admin/media.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function uploadMedia(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) return $response;
        try {
            if ($this->mediaRoot === null) throw new InvalidArgumentException('Media storage is not configured.');
            $upload = $request->files['image'] ?? null;
            if (!is_array($upload)) throw new InvalidArgumentException('A valid image upload is required.');

            $filesToProcess = [];
            if (is_array($upload['name'] ?? null)) {
                $count = count($upload['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (($upload['error'][$i] ?? null) === UPLOAD_ERR_NO_FILE) continue;
                    $filesToProcess[] = [
                        'name' => $upload['name'][$i] ?? '',
                        'tmp_name' => $upload['tmp_name'][$i] ?? '',
                        'error' => $upload['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    ];
                }
            } else {
                if (($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
                    $filesToProcess[] = $upload;
                }
            }
            if ($filesToProcess === []) throw new InvalidArgumentException('A valid image upload is required.');

            $allowedTypes = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_PNG => ['image/png', 'png'], IMAGETYPE_GIF => ['image/gif', 'gif'], IMAGETYPE_WEBP => ['image/webp', 'webp']];
            if (!function_exists('imagecreatefromstring')) throw new InvalidArgumentException('Image uploads require the PHP GD extension for safe decoding.');

            if (!is_dir($this->mediaRoot) && !mkdir($this->mediaRoot, 0775, true) && !is_dir($this->mediaRoot)) throw new \RuntimeException('Unable to create media storage.');

            foreach ($filesToProcess as $file) {
                if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null) || !is_file($file['tmp_name'])) {
                    throw new InvalidArgumentException('A valid image upload is required.');
                }
                $actualSize = filesize($file['tmp_name']);
                if ($actualSize === false || $actualSize <= 0 || $actualSize > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('Images must be larger than 0 bytes and 5 MB or smaller.');
                }
                $image = @getimagesize($file['tmp_name']);
                $imageType = @exif_imagetype($file['tmp_name']);
                if ($image === false || $imageType === false || ($image[0] ?? 0) <= 0 || ($image[1] ?? 0) <= 0) {
                    throw new InvalidArgumentException('The upload must be a decodable image with valid dimensions.');
                }
                $encodedImage = file_get_contents($file['tmp_name']);
                $decodedImage = is_string($encodedImage) ? @imagecreatefromstring($encodedImage) : false;
                if ($decodedImage === false) {
                    throw new InvalidArgumentException('The upload must contain complete, decodable image pixels.');
                }
                unset($decodedImage);
                $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!isset($allowedTypes[$imageType]) || ($image['mime'] ?? null) !== $allowedTypes[$imageType][0] || $detectedMime !== $allowedTypes[$imageType][0]) {
                    throw new InvalidArgumentException('Only consistently encoded JPEG, PNG, GIF, and WebP images are allowed.');
                }
                [, $extension] = $allowedTypes[$imageType];
                $stem = pathinfo(basename($file['name']), PATHINFO_FILENAME);
                $stem = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($stem)), '-') ?: 'image';
                $name = $stem . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                $destination = $this->mediaRoot . '/' . $name;
                if (!move_uploaded_file($file['tmp_name'], $destination) && !rename($file['tmp_name'], $destination)) {
                    throw new \RuntimeException('Unable to store the image.');
                }
            }
            return Response::redirect('/admin/media');
        } catch (InvalidArgumentException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }

    public function deleteMedia(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) return $response;
        try {
            if ($this->mediaRoot === null) throw new InvalidArgumentException('Media storage is not configured.');
            $filename = $request->input('filename');
            if (!is_string($filename) || preg_match('/^[a-z0-9][a-z0-9-]*\.(?:jpg|png|gif|webp)$/', $filename) !== 1) {
                throw new InvalidArgumentException('Invalid image filename.');
            }
            $target = $this->mediaRoot . '/' . $filename;
            if (!is_file($target)) {
                throw new InvalidArgumentException('Image not found.');
            }
            if (!unlink($target)) {
                throw new \RuntimeException('Unable to delete image.');
            }
            return Response::redirect('/admin/media');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function settings(ServerRequest $request): Response
    {
        try { $this->guard->requireAdministrator(); } catch (Unauthorized) { return Response::json(['error' => 'Administrator authentication is required.'], 401); }
        $settings = $this->siteSettings;
        $csrfToken = $this->csrf->token();
        ob_start(); require dirname(__DIR__, 2) . '/templates/admin/settings.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return list<string> */
    private function mediaFiles(): array
    {
        if ($this->mediaRoot === null || !is_dir($this->mediaRoot)) return [];
        return array_values(array_map('basename', array_filter(glob($this->mediaRoot . '/*') ?: [], 'is_file')));
    }

    private function slugFromPath(string $path): string
    {
        if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/draft$#', $path, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid article draft route.');
        }
        return $matches[1];
    }

    private function submittedArticle(ServerRequest $request, ArticleDocument $existing): ArticleDocument
    {
        $title = $request->input('title');
        $date = $request->input('date');
        $body = $request->input('body');
        if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title), 'UTF-8') > 200 || !is_string($date) || !is_string($body) || strlen($body) > 1024 * 1024) {
            throw new InvalidArgumentException('A title up to 200 characters, a date, and Markdown up to 1 MB are required.');
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Article date must use YYYY-MM-DD.');
        }
        $title = trim($title);
        $frontMatter = $this->applyMetadataInputs($request, $existing->frontMatter->with('title', $title)->with('date', $date));
        $document = new ArticleDocument(
            $existing->slug,
            $title,
            $body,
            $frontMatter,
            $existing->sourcePath,
        );
        $this->assertValidMetadata($document);
        return $document;
    }

    private function applyMetadataInputs(ServerRequest $request, FrontMatter $frontMatter): FrontMatter
    {
        $lines = static function (?string $input): ?array {
            if ($input === null) {
                return null;
            }
            return array_values(array_filter(array_map(static fn (string $item): string => trim($item), explode("\n", $input)), static fn (string $item): bool => $item !== ''));
        };
        $text = static function (?string $input): ?string {
            return $input === null ? null : trim($input);
        };

        $summary = $text($request->stringInput('summary'));
        if ($summary !== null) {
            $frontMatter = $summary === '' ? $frontMatter->without('summary') : $frontMatter->with('summary', $summary);
        }
        foreach (['topics', 'sources', 'previous_slugs'] as $listKey) {
            $items = $lines($request->stringInput($listKey));
            if ($items !== null) {
                $frontMatter = $items === [] ? $frontMatter->without($listKey) : $frontMatter->with($listKey, $items);
            }
        }
        foreach (['entities', 'faq', 'hierarchy', 'alt_text', 'internal_links'] as $freeKey) {
            $value = $text($request->stringInput($freeKey));
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                $frontMatter = $frontMatter->without($freeKey);
                continue;
            }
            $existing = $frontMatter->get($freeKey);
            if ($freeKey === 'faq' && ($value[0] ?? '') === '[') {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new InvalidArgumentException('Faq must be valid JSON.', previous: $exception);
                }
                if (!is_array($decoded)) throw new InvalidArgumentException('Faq must be a JSON array.');
                $frontMatter = $frontMatter->with($freeKey, $decoded);
                continue;
            }
            $isJsonShaped = is_array($existing)
                && !(array_is_list($existing) && array_reduce($existing, static fn (bool $ok, mixed $item): bool => $ok && is_string($item), true));
            if ($isJsonShaped) {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new InvalidArgumentException(ucfirst($freeKey) . ' must be valid JSON.', previous: $exception);
                }
                if (!is_array($decoded)) {
                    throw new InvalidArgumentException(ucfirst($freeKey) . ' must be a JSON array or object.');
                }
                $frontMatter = $frontMatter->with($freeKey, $decoded);
            } else {
                $frontMatter = $frontMatter->with($freeKey, is_array($existing) ? ($lines($value) ?? [$value]) : $value);
            }
        }
        $structured = $request->stringInput('structured_data');
        if ($structured !== null) {
            $structured = trim($structured);
            if ($structured === '') {
                $frontMatter = $frontMatter->without('structured_data');
            } else {
                try {
                    $decoded = json_decode($structured, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new InvalidArgumentException('Structured data must be valid JSON.', previous: $exception);
                }
                if (!is_array($decoded) || array_is_list($decoded) || $decoded === []) {
                    throw new InvalidArgumentException('Structured data must be a JSON object.');
                }
                $frontMatter = $frontMatter->with('structured_data', $decoded);
            }
        }
        return $frontMatter;
    }

    private function assertValidMetadata(ArticleDocument $document): void
    {
        foreach (ArticleMetadataValidator::errors($document) as $error) {
            throw new InvalidArgumentException($error);
        }
        $publishedSlugs = array_map(
            static fn (ArticleDocument $article): string => $article->slug,
            array_filter($this->articles->all(), static fn (ArticleDocument $article): bool => $article->frontMatter->get('status') === 'published'),
        );
        foreach ((array) $document->frontMatter->get('previous_slugs', []) as $oldSlug) {
            if (!is_string($oldSlug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $oldSlug) !== 1) {
                continue;
            }
            if (in_array($oldSlug, $publishedSlugs, true)) {
                throw new InvalidArgumentException(sprintf('Redirect slug "%s" collides with a published route.', $oldSlug));
            }
        }
    }

    private function maybeEnqueueGeoReview(ArticleDocument $document): void
    {
        if ($this->queue === null || !GeoConfiguration::fromEnvironment()->configured) {
            return;
        }
        $fm = $document->frontMatter;
        $hasEmptyField = GeoAutoMerge::isEmpty($fm->get('summary'))
            || GeoAutoMerge::isEmpty($fm->get('entities'))
            || GeoAutoMerge::isEmpty($fm->get('faq'))
            || GeoAutoMerge::isEmpty($fm->get('alt_text'));
        if (!$hasEmptyField) {
            return;
        }
        try {
            $version = $this->versions->captureReviewInput($document);
            $this->queue->enqueueGeoReview($document, 'review-inputs/' . $version . '.md');
        } catch (\Throwable) {
            // Silently ignore queueing conflicts or background errors
        }
    }
}
