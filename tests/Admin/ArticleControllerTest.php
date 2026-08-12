<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\ArticleController;
use HolyMD\Admin\VersionService;
use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleRepository;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ArticleControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-admin-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/articles', 0777, true);
        mkdir($this->root . '/versions', 0777, true);
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\n---\nOriginal body\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_draft_save_rejects_an_unauthenticated_request(): void
    {
        $router = $this->router([]);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Updated body',
        ]));

        self::assertSame(401, $response->status);
    }

    public function test_draft_save_rejects_a_request_without_a_valid_csrf_token(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Updated body', 'csrf_token' => 'wrong-token',
        ]));

        self::assertSame(419, $response->status);
    }

    public function test_draft_save_writes_markdown_creates_a_version_and_round_trips_the_body(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'Revised note',
            'date' => '2026-08-12',
            'body' => "# Exact body\n\nTrailing spaces  \n",
            'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('versionId', $payload);
        self::assertSame("# Exact body\n\nTrailing spaces  \n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
        self::assertSame(1, count(glob($this->root . '/versions/*.md') ?: []));
    }

    public function test_restore_is_authorized_and_restores_a_snapshot(): void
    {
        $versions = new VersionService($this->root . '/versions');
        $document = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        $version = $versions->snapshot($document);
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\n---\nChanged body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/restore/' . $version->value, [], ['csrf_token' => 'expected-token']));

        self::assertSame(303, $response->status);
        self::assertSame("Original body\n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
    }

    /** @param array<string, mixed> $session */
    private function router(array $session): Router
    {
        $repository = new ArticleRepository($this->root . '/articles');
        $controller = new ArticleController($repository, new VersionService($this->root . '/versions'), new AdminGuard($session), new Csrf($session));
        return Router::admin($controller);
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $child) {
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
