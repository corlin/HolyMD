<?php
declare(strict_types=1);
namespace HolyMD\Tests\Geo;
use HolyMD\Admin\ArticleController;
use HolyMD\Admin\VersionService;
use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleRepository;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\AiResponse;
use HolyMD\Geo\GeoController;
use HolyMD\Geo\GeoProposalStore;
use HolyMD\Geo\GeoReviewService;
use HolyMD\Geo\InMemoryGeoProposalStore;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class GeoControllerTest extends TestCase
{
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir() . '/holymd-route-' . bin2hex(random_bytes(5)); mkdir($this->root, 0777, true); file_put_contents($this->root . '/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\n---\nExact body\n"); }
    protected function tearDown(): void { unlink($this->root . '/first-note.md'); rmdir($this->root); }
    public function test_review_and_accept_routes_require_authentication_and_update_front_matter_only(): void {
        $store = new InMemoryGeoProposalStore(); $router = $this->router(['admin_user_id' => 1, 'csrf_token' => 'token'], $store);
        $review = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/geo/review', [], ['csrf_token' => 'token']));
        self::assertSame(200, $review->status); $payload = json_decode($review->body, true, flags: JSON_THROW_ON_ERROR); $id = $payload['proposals'][0]['id'];
        $accepted = $router->dispatch(new ServerRequest('POST', '/admin/geo/proposals/' . $id . '/accept', [], ['csrf_token' => 'token']));
        self::assertSame(200, $accepted->status); self::assertSame('Summary', json_decode($accepted->body, true, flags: JSON_THROW_ON_ERROR)['frontMatter']['summary']);
        self::assertSame("Exact body\n", (new ArticleRepository($this->root))->read('first-note')->bodyMarkdown);
        self::assertSame(401, $this->router([], new InMemoryGeoProposalStore())->dispatch(new ServerRequest('POST', '/admin/articles/first-note/geo/review'))->status);
    }
    private function router(array $session, GeoProposalStore $store): Router {
        $articles = new ArticleRepository($this->root); $controller = new ArticleController($articles, new VersionService($this->root . '/versions'), new AdminGuard($session), new Csrf($session));
        $client = new class implements AiClient { public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { return new AiResponse('{"proposals":[{"type":"summary","value":"Summary"}],"findings":[]}'); } };
        return Router::admin($controller, new GeoController($articles, new GeoReviewService($client), $store, new AdminGuard($session), new Csrf($session)));
    }
}
