<?php

declare(strict_types=1);

namespace HolyMD\Http;

use HolyMD\Admin\ArticleController;

final readonly class Router
{
    public function __construct(private ArticleController $articles)
    {
    }

    public static function admin(ArticleController $articles): self
    {
        return new self($articles);
    }

    public function dispatch(ServerRequest $request): Response
    {
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
