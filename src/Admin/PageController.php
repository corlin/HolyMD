<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use HolyMD\Publish\PublishService;
use InvalidArgumentException;

final readonly class PageController
{
    use AdminAuthorizationTrait;

    public function __construct(
        private ArticleRepository $pages,
        private AdminGuard $guard,
        private Csrf $csrf,
        private ?PublishService $publisher = null,
        private ?VersionService $versions = null,
    ) {
    }

    public function index(ServerRequest $request): Response
    {
        if (($auth = $this->requireAdmin()) !== null) {
            return $auth;
        }
        $pages = $this->pages->all();
        $csrfToken = $this->csrf->token();
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/pages/index.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function new(ServerRequest $request): Response
    {
        if (($auth = $this->requireAdmin()) !== null) {
            return $auth;
        }
        $csrfToken = $this->csrf->token();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/pages/new.php';
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
                throw new InvalidArgumentException('Page title, date, and Markdown body are required.');
            }
            $title = trim($title);
            $slug = $this->safeSlug($submittedSlug !== '' ? $submittedSlug : $title);
            if ($this->pages->exists($slug)) {
                throw new InvalidArgumentException('A page with this slug already exists.');
            }
            $this->pages->validateSlug($slug);

            $meta = ['title' => $title, 'slug' => $slug, 'date' => $date, 'status' => 'draft'];
            $navOrder = $request->input('nav_order');
            if (is_numeric($navOrder)) {
                $meta['nav_order'] = (int) $navOrder;
            }
            $description = $request->input('description');
            if (is_string($description) && trim($description) !== '') {
                $meta['description'] = trim($description);
            }

            $document = new ArticleDocument($slug, $title, $body, new FrontMatter($meta), $slug . '.md');
            $this->pages->write($document);
            return Response::redirect('/admin/pages/' . rawurlencode($slug) . '/edit');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function edit(ServerRequest $request): Response
    {
        if (($auth = $this->requireAdmin()) !== null) {
            return $auth;
        }
        try {
            if (preg_match('#^/admin/pages/([a-z0-9]+(?:-[a-z0-9]+)*)/edit$#', $request->path, $matches) !== 1) {
                throw new InvalidArgumentException('Invalid page edit route.');
            }
            $page = $this->pages->read($matches[1]);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'Page not found.'], 404);
        }
        $pageChecksum = hash('sha256', $page->serialize());
        $csrfToken = $this->csrf->token();
        $versions = $this->versions?->list($page->slug) ?? [];
        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/pages/edit.php';
        return new Response(200, (string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function saveDraft(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        try {
            $slug = $this->slugFromPath($request->path);
            $existing = $this->pages->read($slug);
            $document = $this->submittedPage($request, $existing);
            $expectedChecksum = $request->input('expected_checksum');
            if (!is_string($expectedChecksum) || !$this->pages->writeIfUnchanged($document, $expectedChecksum)) {
                return Response::json(['error' => 'The page changed in another editor session. Reload before saving again.'], 409);
            }
            return Response::json(['saved' => true, 'checksum' => hash('sha256', $document->serialize())]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function publish(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        if (preg_match('#^/admin/pages/([a-z0-9]+(?:-[a-z0-9]+)*)/(publish|withdraw)$#', $request->path, $matches) !== 1) {
            return Response::json(['error' => 'Invalid page action route.'], 422);
        }
        $slug = $matches[1];
        $action = $matches[2];
        try {
            $page = $this->pages->read($slug);
            if ($action === 'publish' && $request->input('body') !== null) {
                $page = $this->submittedPage($request, $page);
            }
            $nextStatus = $action === 'publish' ? 'published' : 'draft';
            $updated = $page->withFrontMatter($page->frontMatter->with('status', $nextStatus));
            $this->pages->write($updated);
            if ($action === 'publish' && $this->versions !== null) {
                $versionId = $this->versions->recordPublished($updated);
                $updated = $updated->withFrontMatter($updated->frontMatter->with('published_version', $versionId));
                $this->pages->write($updated);
            }
            if ($this->publisher !== null) {
                $this->publisher->rebuild();
            }
            return Response::redirect('/admin/pages/' . rawurlencode($slug) . '/edit');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function restore(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        if (preg_match('#^/admin/pages/([a-z0-9]+(?:-[a-z0-9]+)*)/restore/([a-f0-9]{32})$#', $request->path, $matches) !== 1) {
            return Response::json(['error' => 'Invalid page restore route.'], 422);
        }
        try {
            $slug = $matches[1];
            $versionId = $matches[2];
            $existing = $this->pages->read($slug);
            if ($this->versions === null) {
                throw new InvalidArgumentException('Version service is unavailable.');
            }
            $restored = $this->versions->restore($versionId, $slug);
            $this->pages->write($restored->withFrontMatter($restored->frontMatter->with('status', 'draft')));
            return Response::redirect('/admin/pages/' . rawurlencode($slug) . '/edit');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function delete(ServerRequest $request): Response
    {
        if (($response = $this->authorizeMutation($request)) !== null) {
            return $response;
        }
        if (preg_match('#^/admin/pages/([a-z0-9]+(?:-[a-z0-9]+)*)/delete$#', $request->path, $matches) !== 1) {
            return Response::json(['error' => 'Invalid delete route.'], 422);
        }
        try {
            $slug = $matches[1];
            $page = $this->pages->read($slug);
            if ($page->frontMatter->get('status', 'draft') !== 'draft') {
                throw new InvalidArgumentException('Only draft pages can be deleted. Withdraw a published page first.');
            }
            if ($request->input('confirm_slug') !== $page->slug) {
                throw new InvalidArgumentException('Type the page slug to confirm deletion.');
            }
            $this->pages->delete($slug);
            $this->versions?->purge($slug);
            return Response::redirect('/admin/pages');
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    private function submittedPage(ServerRequest $request, ArticleDocument $existing): ArticleDocument
    {
        $title = $request->input('title', $existing->title);
        $body = $request->input('body', $existing->bodyMarkdown);
        $date = $request->input('date', (string) $existing->frontMatter->get('date'));
        if (!is_string($title) || trim($title) === '' || !is_string($body) || !is_string($date)) {
            throw new InvalidArgumentException('Page title, date, and body are required.');
        }
        $frontMatterData = $existing->frontMatter->all();
        $frontMatterData['title'] = trim($title);
        $frontMatterData['date'] = trim($date);

        $navOrder = $request->input('nav_order');
        if ($navOrder !== null && $navOrder !== '') {
            if (is_numeric($navOrder)) {
                $frontMatterData['nav_order'] = (int) $navOrder;
            }
        } else {
            unset($frontMatterData['nav_order']);
        }

        $description = $request->input('description');
        if (is_string($description) && trim($description) !== '') {
            $frontMatterData['description'] = trim($description);
        } else {
            unset($frontMatterData['description']);
        }

        return new ArticleDocument($existing->slug, trim($title), $body, new FrontMatter($frontMatterData), $existing->sourcePath);
    }

    private function slugFromPath(string $path): string
    {
        if (preg_match('#^/admin/pages/([a-z0-9]+(?:-[a-z0-9]+)*)/draft$#', $path, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid page draft route.');
        }
        return $matches[1];
    }
}
