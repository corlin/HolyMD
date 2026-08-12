<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\Unauthorized;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use HolyMD\Publish\ArticleId;
use HolyMD\Publish\PublishService;
use HolyMD\Queue\MySqlJobQueue;
use InvalidArgumentException;

final readonly class ArticleController
{
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
    ) {
    }

    public function saveDraft(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        try {
            $slug = $this->slugFromPath($request->path);
            $existing = $this->articles->read($slug);
            $title = $request->input('title', $existing->title);
            $date = $request->input('date', $existing->frontMatter->get('date'));
            $body = $request->input('body');
            if (!is_string($title) || !is_string($date) || !is_string($body)) {
                throw new InvalidArgumentException('Draft title, date, and Markdown body are required.');
            }
            $document = new ArticleDocument($slug, $title, $body, $existing->frontMatter->with('title', $title)->with('date', $date), $existing->sourcePath);
            $this->articles->write($document);
            $version = $this->versions->snapshot($document);
            return Response::json(['saved' => true, 'versionId' => $version->value]);
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
            $document = new ArticleDocument($slug, $title, $body, new \HolyMD\Content\FrontMatter(['title' => $title, 'slug' => $slug, 'date' => $date]), $slug . '.md');
            $this->articles->write($document);
            $this->versions->snapshot($document);
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
            $version = new VersionId($matches[2]);
            $document = $this->versions->restore($version, $matches[1]);
            $this->articles->write($document);
            $this->versions->snapshot($document);
            return Response::redirect('/admin/articles/' . $document->slug . '/edit');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function publish(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/(publish|withdraw)$#', $request->path, $matches) !== 1) {
            return Response::json(['error' => 'Invalid publication route.'], 422);
        }
        if ($this->publisher === null) {
            return Response::json(['error' => 'Publishing is not configured.'], 503);
        }
        try {
            if ($this->queue !== null) {
                $jobId = $this->queue->enqueueBuild($this->articles->read($matches[1]), $matches[2]);
                return $this->publicationResponse($matches[1], $matches[2], true, $jobId);
            }
            $result = $matches[2] === 'publish' ? $this->publisher->publish(new ArticleId($matches[1])) : $this->publisher->withdraw(new ArticleId($matches[1]));
            return $this->publicationResponse($matches[1], $matches[2]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }
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
            if (!is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_string($upload['name'] ?? null) || !is_int($upload['size'] ?? null)) throw new InvalidArgumentException('A valid image upload is required.');
            if ($upload['size'] <= 0 || $upload['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('Images must be 5 MB or smaller.');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) throw new InvalidArgumentException('Only JPEG, PNG, GIF, and WebP images are allowed.');
            $stem = pathinfo(basename($upload['name']), PATHINFO_FILENAME);
            $stem = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($stem)), '-') ?: 'image';
            $name = $stem . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
            if (!is_dir($this->mediaRoot) && !mkdir($this->mediaRoot, 0775, true) && !is_dir($this->mediaRoot)) throw new \RuntimeException('Unable to create media storage.');
            $destination = $this->mediaRoot . '/' . $name;
            if (!move_uploaded_file($upload['tmp_name'], $destination) && !rename($upload['tmp_name'], $destination)) throw new \RuntimeException('Unable to store the image.');
            return Response::redirect('/admin/media');
        } catch (InvalidArgumentException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }

    public function settings(ServerRequest $request): Response
    {
        try { $this->guard->requireAdministrator(); } catch (Unauthorized) { return Response::json(['error' => 'Administrator authentication is required.'], 401); }
        $settings = $this->siteSettings;
        ob_start(); require dirname(__DIR__, 2) . '/templates/admin/settings.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return list<string> */
    private function mediaFiles(): array
    {
        if ($this->mediaRoot === null || !is_dir($this->mediaRoot)) return [];
        return array_values(array_map('basename', array_filter(glob($this->mediaRoot . '/*') ?: [], 'is_file')));
    }

    public function unsupportedMutation(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) return $response;
        return Response::json(['error' => 'Settings mutation is not configured.'], 503);
    }

    private function authorizeMutation(ServerRequest $request): ?Response
    {
        try {
            $this->guard->requireAdministrator();
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }
        if (!$this->csrf->valid($request)) {
            return Response::json(['error' => 'CSRF token is invalid.'], 419);
        }
        return null;
    }

    private function slugFromPath(string $path): string
    {
        if (preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/draft$#', $path, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid article draft route.');
        }
        return $matches[1];
    }

    private function safeSlug(string $value): string
    {
        $value = trim($value);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new InvalidArgumentException('Article title or slug must contain letters or numbers.');
        }
        return $slug;
    }
}
