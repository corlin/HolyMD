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
use InvalidArgumentException;

final readonly class ArticleController
{
    public function __construct(
        private ArticleRepository $articles,
        private VersionService $versions,
        private AdminGuard $guard,
        private Csrf $csrf,
        private ?PublishService $publisher = null,
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
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/articles/index.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
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
            $result = $matches[2] === 'publish' ? $this->publisher->publish(new ArticleId($matches[1])) : $this->publisher->withdraw(new ArticleId($matches[1]));
            return Response::json(['action' => $matches[2], 'slug' => $matches[1], 'articleCount' => $result->manifest->articleCount, 'validation' => $result->validation->text()]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }
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
}
