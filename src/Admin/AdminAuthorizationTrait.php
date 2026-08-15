<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use HolyMD\Auth\Unauthorized;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use InvalidArgumentException;

trait AdminAuthorizationTrait
{
    private function requireAdmin(): ?Response
    {
        try {
            $this->guard->requireAdministrator();
            return null;
        } catch (Unauthorized) {
            return Response::json(['error' => 'Administrator authentication is required.'], 401);
        }
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

    private function safeSlug(string $value): string
    {
        $value = trim($value);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value));
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new InvalidArgumentException('Title or slug must contain letters or numbers.');
        }
        return $slug;
    }
}
