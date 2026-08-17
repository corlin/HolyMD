<?php

declare(strict_types=1);

namespace HolyMD\Http;

use HolyMD\Admin\ArticleController;
use HolyMD\Admin\GeoDashboardController;
use HolyMD\Admin\JobsController;
use HolyMD\Admin\PageController;
use HolyMD\Admin\ProfileController;
use HolyMD\Auth\AuthController;
use HolyMD\Geo\GeoController;

final readonly class Router
{
    public function __construct(
        private ?ArticleController $articles = null,
        private ?GeoController $geo = null,
        private ?AuthController $auth = null,
        private ?JobsController $jobs = null,
        private ?ProfileController $profile = null,
        private ?PageController $pages = null,
        private ?GeoDashboardController $geoDashboard = null,
    ) {
    }

    public function dispatch(ServerRequest $request): Response
    {
        if ($this->auth !== null && $request->path === '/admin/login' && in_array($request->method, ['GET', 'POST'], true)) return $this->auth->login($request);
        if ($this->auth !== null && $request->path === '/admin/logout' && $request->method === 'POST') return $this->auth->logout($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/geo/review$#', $request->path) === 1) return $this->geo->review($request);
        if ($this->geo !== null && $request->method === 'GET' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/geo/review$#', $request->path) === 1) return $this->geo->status($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/geo/proposals/[A-Za-z0-9][A-Za-z0-9_-]{0,127}/accept$#', $request->path) === 1) return $this->geo->accept($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/geo/proposals/[A-Za-z0-9][A-Za-z0-9_-]{0,127}/reject$#', $request->path) === 1) return $this->geo->reject($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/geo/proposals/[A-Za-z0-9][A-Za-z0-9_-]{0,127}/edit$#', $request->path) === 1) return $this->geo->edit($request);
        if ($this->articles !== null && $request->method === 'GET' && $request->path === '/admin/articles') {
            return $this->articles->index($request);
        }
        if ($this->articles !== null && $request->method === 'POST' && $request->path === '/admin/markdown/preview') return $this->articles->previewMarkdown($request);
        if ($this->articles !== null && $request->path === '/admin/articles/new' && $request->method === 'GET') return $this->articles->new($request);
        if ($this->articles !== null && $request->path === '/admin/articles/new' && $request->method === 'POST') return $this->articles->create($request);
        if ($this->articles !== null && $request->method === 'GET' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/edit$#', $request->path) === 1) {
            return $this->articles->edit($request);
        }
        if ($this->articles !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/draft$#', $request->path) === 1) {
            return $this->articles->saveDraft($request);
        }
        if ($this->articles !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/restore/[a-f0-9]{32}$#', $request->path) === 1) {
            return $this->articles->restore($request);
        }
        if ($this->articles !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9-]+/preflight$#', $request->path) === 1) {
            return $this->articles->preflight($request);
        }
        if ($this->articles !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9-]+/(publish|withdraw)$#', $request->path) === 1) {
            return $this->articles->publish($request);
        }
        if ($this->articles !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9-]+/delete$#', $request->path) === 1) return $this->articles->delete($request);
        if ($this->articles !== null && $request->method === 'GET' && $request->path === '/admin/media') return $this->articles->media($request);
        if ($this->articles !== null && $request->method === 'POST' && $request->path === '/admin/media') return $this->articles->uploadMedia($request);
        if ($this->articles !== null && $request->method === 'POST' && $request->path === '/admin/media/delete') return $this->articles->deleteMedia($request);
        if ($this->articles !== null && $request->method === 'GET' && $request->path === '/admin/settings') return $this->articles->settings($request);
        if ($this->jobs !== null && $request->method === 'GET' && $request->path === '/admin/jobs') return $this->jobs->index($request);
        if ($this->geoDashboard !== null && $request->method === 'GET' && $request->path === '/admin/geo') return $this->geoDashboard->index($request);
        if ($this->profile !== null && $request->path === '/admin/profile' && $request->method === 'GET') return $this->profile->index($request);
        if ($this->profile !== null && $request->path === '/admin/profile' && $request->method === 'POST') return $this->profile->update($request);
        if ($this->pages !== null && $request->method === 'GET' && $request->path === '/admin/pages') return $this->pages->index($request);
        if ($this->pages !== null && $request->path === '/admin/pages/new' && $request->method === 'GET') return $this->pages->new($request);
        if ($this->pages !== null && $request->path === '/admin/pages/new' && $request->method === 'POST') return $this->pages->create($request);
        if ($this->pages !== null && $request->method === 'GET' && preg_match('#^/admin/pages/[a-z0-9]+(?:-[a-z0-9]+)*/edit$#', $request->path) === 1) return $this->pages->edit($request);
        if ($this->pages !== null && $request->method === 'POST' && preg_match('#^/admin/pages/[a-z0-9]+(?:-[a-z0-9]+)*/draft$#', $request->path) === 1) return $this->pages->saveDraft($request);
        if ($this->pages !== null && $request->method === 'POST' && preg_match('#^/admin/pages/[a-z0-9-]+/(publish|withdraw)$#', $request->path) === 1) return $this->pages->publish($request);
        if ($this->pages !== null && $request->method === 'POST' && preg_match('#^/admin/pages/[a-z0-9-]+/restore/[a-f0-9]{32}$#', $request->path) === 1) return $this->pages->restore($request);
        if ($this->pages !== null && $request->method === 'POST' && preg_match('#^/admin/pages/[a-z0-9-]+/delete$#', $request->path) === 1) return $this->pages->delete($request);
        return Response::json(['error' => 'Not found.'], 404);
    }
}
