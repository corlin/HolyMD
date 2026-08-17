<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\PageController;
use HolyMD\Admin\VersionService;
use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase
{
    private string $root;
    private VersionService $versionService;
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-page-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/pages', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->root . '/pages/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->root . '/pages')) {
            rmdir($this->root . '/pages');
        }
        $vFiles = glob($this->root . '/versions/*') ?: [];
        foreach ($vFiles as $file) {
            unlink($file);
        }
        if (is_dir($this->root . '/versions')) {
            rmdir($this->root . '/versions');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function test_pages_index_requires_authentication(): void
    {
        $response = $this->router()->dispatch(new ServerRequest('GET', '/admin/pages'));
        self::assertSame(401, $response->status);
    }

    public function test_pages_crud_and_publishing(): void
    {
        $this->session = ['admin_user_id' => 9, 'csrf_token' => 'test-csrf'];
        $router = $this->router();

        $newResponse = $router->dispatch(new ServerRequest('GET', '/admin/pages/new'));
        self::assertSame(200, $newResponse->status);
        self::assertStringContainsString('pattern="[A-Za-z0-9 _\\-]+"', $newResponse->body);

        // 1. Create page
        $createResponse = $router->dispatch(new ServerRequest('POST', '/admin/pages/new', [], [
            'csrf_token' => 'test-csrf',
            'title' => 'Terms of Service',
            'slug' => 'terms',
            'date' => '2026-08-14',
            'nav_order' => '2',
            'description' => 'Terms of service for HolyMD',
            'body' => '# Terms of Service',
        ]));
        self::assertSame(303, $createResponse->status);
        self::assertSame('/admin/pages/terms/edit', $createResponse->headers['Location']);

        // 2. Index shows created page
        $indexResponse = $router->dispatch(new ServerRequest('GET', '/admin/pages'));
        self::assertSame(200, $indexResponse->status);
        self::assertStringContainsString('Terms of Service', $indexResponse->body);
        self::assertStringContainsString('/terms/', $indexResponse->body);

        // 3. Edit page
        $editResponse = $router->dispatch(new ServerRequest('GET', '/admin/pages/terms/edit'));
        self::assertSame(200, $editResponse->status);
        self::assertStringContainsString('Terms of Service', $editResponse->body);

        // 4. Save draft
        $repo = new ArticleRepository($this->root . '/pages', ArticleRepository::RESERVED_PAGE_SLUGS);
        $page = $repo->read('terms');
        $checksum = hash('sha256', $page->serialize());

        $draftResponse = $router->dispatch(new ServerRequest('POST', '/admin/pages/terms/draft', [], [
            'csrf_token' => 'test-csrf',
            'expected_checksum' => $checksum,
            'title' => 'Terms of Service Updated',
            'date' => '2026-08-14',
            'body' => '## Updated body',
        ]));
        self::assertSame(200, $draftResponse->status);

        // 5. Publish page
        $publishResponse = $router->dispatch(new ServerRequest('POST', '/admin/pages/terms/publish', [], [
            'csrf_token' => 'test-csrf',
            'expected_checksum' => $checksum,
            'title' => 'Terms of Service Updated',
            'date' => '2026-08-14',
            'body' => '## Updated body',
        ]));
        self::assertSame(303, $publishResponse->status);
        self::assertSame('published', $repo->read('terms')->frontMatter->get('status'));
        $versions = $this->versionService->list('terms');
        self::assertCount(1, $versions);
        $v1 = $versions[0];

        // 6. Delete when published is rejected
        $deletePublished = $router->dispatch(new ServerRequest('POST', '/admin/pages/terms/delete', [], [
            'csrf_token' => 'test-csrf',
            'confirm_slug' => 'terms',
        ]));
        self::assertSame(422, $deletePublished->status);

        // 7. Withdraw page
        $withdrawResponse = $router->dispatch(new ServerRequest('POST', '/admin/pages/terms/withdraw', [], [
            'csrf_token' => 'test-csrf',
        ]));
        self::assertSame(303, $withdrawResponse->status);
        self::assertSame('draft', $repo->read('terms')->frontMatter->get('status'));

        // 8. Restore version
        $restoreResponse = $router->dispatch(new ServerRequest('POST', '/admin/pages/terms/restore/' . $v1, [], [
            'csrf_token' => 'test-csrf',
        ]));
        self::assertSame(303, $restoreResponse->status);
        self::assertSame('Terms of Service Updated', $repo->read('terms')->title);

        // 9. Delete page (cascading purge versions)
        $deleteResponse = $router->dispatch(new ServerRequest('POST', '/admin/pages/terms/delete', [], [
            'csrf_token' => 'test-csrf',
            'confirm_slug' => 'terms',
        ]));
        self::assertSame(303, $deleteResponse->status);
        self::assertFalse($repo->exists('terms'));
        self::assertEmpty($this->versionService->list('terms'));
    }

    private function router(): Router
    {
        $guard = new AdminGuard($this->session);
        $csrf = new Csrf($this->session);
        $repo = new ArticleRepository($this->root . '/pages', ArticleRepository::RESERVED_PAGE_SLUGS);
        $this->versionService = new VersionService($this->root . '/versions');
        $controller = new PageController($repo, $guard, $csrf, versions: $this->versionService);
        return new Router(pages: $controller);
    }
}
