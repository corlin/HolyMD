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
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
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

    public function test_new_article_form_requires_administrator_authentication(): void
    {
        $response = $this->router([])->dispatch(new ServerRequest('GET', '/admin/articles/new'));

        self::assertSame(401, $response->status);
    }

    public function test_new_article_form_includes_csrf_protected_creation_controls(): void
    {
        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('GET', '/admin/articles/new'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/admin/articles/new"', $response->body);
        self::assertStringContainsString('value="expected-token"', $response->body);
    }

    public function test_new_article_creates_safe_markdown_and_first_version_then_redirects_to_edit(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/new', [], [
            'title' => 'A Fresh Note!', 'slug' => 'Fresh Note 2026', 'date' => '2026-08-12', 'body' => "# Hello\n", 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(303, $response->status);
        self::assertSame('/admin/articles/fresh-note-2026/edit', $response->headers['Location']);
        $document = (new ArticleRepository($this->root . '/articles'))->read('fresh-note-2026');
        self::assertSame('A Fresh Note!', $document->title);
        self::assertSame("# Hello\n", $document->bodyMarkdown);
        self::assertSame('fresh-note-2026', $document->frontMatter->get('slug'));
        self::assertCount(1, (new VersionService($this->root . '/versions'))->list('fresh-note-2026'));
    }

    public function test_new_article_uses_title_when_slug_is_blank_and_rejects_bad_csrf_or_duplicates(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $payload = ['title' => 'Title Fallback', 'slug' => '', 'date' => '2026-08-12', 'body' => '', 'csrf_token' => 'expected-token'];

        self::assertSame(303, $router->dispatch(new ServerRequest('POST', '/admin/articles/new', [], $payload))->status);
        self::assertSame(422, $router->dispatch(new ServerRequest('POST', '/admin/articles/new', [], $payload))->status);
        $payload['csrf_token'] = 'wrong-token';
        self::assertSame(419, $router->dispatch(new ServerRequest('POST', '/admin/articles/new', [], $payload))->status);
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

    public function test_identical_saved_article_reuses_the_same_stable_version(): void
    {
        $versions = new VersionService($this->root . '/versions');
        $document = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertSame($versions->snapshot($document)->value, $versions->snapshot($document)->value);
        self::assertCount(1, glob($this->root . '/versions/*.md') ?: []);
    }

    public function test_publish_is_an_authorized_csrf_protected_real_publication_action(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', [], ['csrf_token' => 'expected-token']));

        self::assertSame(200, $response->status);
        self::assertSame('publish', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['action']);
        self::assertFileExists($this->root . '/public/articles/first-note/index.html');
    }

    public function test_publish_rejects_a_missing_csrf_token(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish'));

        self::assertSame(419, $response->status);
    }

    public function test_versions_are_scoped_to_the_current_article_for_listing_and_restore(): void
    {
        file_put_contents($this->root . '/articles/second-note.md', "---\ntitle: Second note\nslug: second-note\ndate: 2026-08-12\n---\nSecond body\n");
        $versions = new VersionService($this->root . '/versions');
        $secondVersion = $versions->snapshot((new ArticleRepository($this->root . '/articles'))->read('second-note'));
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        self::assertSame([], $versions->list('first-note'));
        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/restore/' . $secondVersion->value, [], ['csrf_token' => 'expected-token']));

        self::assertSame(422, $response->status);
        self::assertSame("Original body\n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
    }

    /** @param array<string, mixed> $session */
    private function router(array $session): Router
    {
        $repository = new ArticleRepository($this->root . '/articles');
        $publisher = new PublishService($repository, new StaticBuilder(), new AtomicPublicTree(), $this->root . '/public', 'Test publication', 'https://example.test', 'Ada Test', 'About Ada.');
        $controller = new ArticleController($repository, new VersionService($this->root . '/versions'), new AdminGuard($session), new Csrf($session), $publisher);
        return Router::admin($controller);
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $path . '/' . $name;
            is_dir($child) && !is_link($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
