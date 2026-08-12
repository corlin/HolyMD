<?php

declare(strict_types=1);

namespace HolyMD\Http;

use HolyMD\Admin\ArticleController;
use HolyMD\Geo\GeoController;

final readonly class Router
{
    public function __construct(private ArticleController $articles, private ?GeoController $geo = null)
    {
    }

    public static function admin(ArticleController $articles, ?GeoController $geo = null): self
    {
        return new self($articles, $geo);
    }

    public function dispatch(ServerRequest $request): Response
    {
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/geo/review$#', $request->path) === 1) return $this->geo->review($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/geo/proposals/[A-Za-z0-9][A-Za-z0-9_-]{0,127}/accept$#', $request->path) === 1) return $this->geo->accept($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/geo/proposals/[A-Za-z0-9][A-Za-z0-9_-]{0,127}/reject$#', $request->path) === 1) return $this->geo->reject($request);
        if ($this->geo !== null && $request->method === 'POST' && preg_match('#^/admin/geo/proposals/[A-Za-z0-9][A-Za-z0-9_-]{0,127}/edit$#', $request->path) === 1) return $this->geo->edit($request);
        if ($request->method === 'GET' && $request->path === '/admin/articles') {
            return $this->articles->index($request);
        }
        if ($request->method === 'GET' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/edit$#', $request->path) === 1) {
            return $this->articles->edit($request);
        }
        if ($request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/draft$#', $request->path) === 1) {
            return $this->articles->saveDraft($request);
        }
        if ($request->method === 'POST' && preg_match('#^/admin/articles/[a-z0-9]+(?:-[a-z0-9]+)*/restore/[a-f0-9]{32}$#', $request->path) === 1) {
            return $this->articles->restore($request);
        }
        if ($request->method === 'POST' && (preg_match('#^/admin/articles/[a-z0-9-]+/(publish|withdraw)$#', $request->path) === 1 || $request->path === '/admin/settings')) {
            return $this->articles->unsupportedMutation($request);
        }
        return Response::json(['error' => 'Not found.'], 404);
    }
}
