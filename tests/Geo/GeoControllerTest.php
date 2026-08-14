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
    public function test_review_accept_and_reject_routes_require_authentication_and_accept_only_marks_status(): void {
        $store = new InMemoryGeoProposalStore(); $router = $this->router(['admin_user_id' => 1, 'csrf_token' => 'token'], $store);
        $review = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/geo/review', [], ['csrf_token' => 'token']));
        self::assertSame(200, $review->status); $payload = json_decode($review->body, true, flags: JSON_THROW_ON_ERROR); $id = $payload['proposals'][0]['id'];
        $accepted = $router->dispatch(new ServerRequest('POST', '/admin/geo/proposals/' . $id . '/accept', [], ['csrf_token' => 'token']));
        self::assertSame(200, $accepted->status); $acceptedPayload = json_decode($accepted->body, true, flags: JSON_THROW_ON_ERROR); self::assertTrue($acceptedPayload['accepted']); self::assertArrayNotHasKey('frontMatter', $acceptedPayload);
        $article = (new ArticleRepository($this->root))->read('first-note');
        self::assertNull($article->frontMatter->get('summary'), 'Accept must not write the article file; the editor save pipeline does.');
        self::assertSame("Exact body\n", $article->bodyMarkdown);
        self::assertSame('accepted', $store->get(new \HolyMD\Geo\GeoProposalId($id))->status);
        $rejected = $router->dispatch(new ServerRequest('POST', '/admin/geo/proposals/' . $id . '/reject', [], ['csrf_token' => 'token']));
        self::assertSame(200, $rejected->status); self::assertTrue(json_decode($rejected->body, true, flags: JSON_THROW_ON_ERROR)['rejected']);
        self::assertSame(422, $router->dispatch(new ServerRequest('POST', '/admin/geo/proposals/' . $id . '/accept', [], ['csrf_token' => 'token']))->status, 'A decided proposal cannot be accepted again.');
        self::assertSame(401, $this->router([], new InMemoryGeoProposalStore())->dispatch(new ServerRequest('POST', '/admin/articles/first-note/geo/review'))->status);
        self::assertSame(401, $this->router([], new InMemoryGeoProposalStore())->dispatch(new ServerRequest('GET', '/admin/articles/first-note/geo/review'))->status);
        self::assertSame(401, $this->router([], new InMemoryGeoProposalStore())->dispatch(new ServerRequest('POST', '/admin/geo/proposals/' . $id . '/accept'))->status);
    }

    public function test_admin_javascript_polls_queued_review_status_before_mapping_proposals(): void {
        $javascript = (string) file_get_contents(__DIR__ . '/../../public/assets/admin.js');
        self::assertStringContainsString('if (payload.queued)', $javascript);
        self::assertStringContainsString("payload.status === 'completed'", $javascript);
        self::assertStringContainsString('await poll()', $javascript);
        self::assertStringContainsString('attempt < 60', $javascript);
        self::assertStringContainsString('waiting for Cron worker', $javascript);
        self::assertStringContainsString('Refresh GEO status', $javascript);
        self::assertStringNotContainsString('payload.proposals.map', $javascript);
        self::assertStringContainsString("typeof proposal.value === 'string'", $javascript);
        self::assertStringContainsString('resumeStatus()', $javascript);
        self::assertStringContainsString("payload.status === 'running'", $javascript);
        self::assertStringContainsString("payload.status === 'failed'", $javascript);
        self::assertStringContainsString('data-geo-field', $javascript);
        self::assertStringContainsString('flushSave', $javascript);
        self::assertStringContainsString('data-geo-catchall', $javascript);
        self::assertStringNotContainsString('prompt(', $javascript);
    }
    public function test_edit_decodes_structured_metadata_and_rejects_malformed_json_before_accept(): void {
        $store = new InMemoryGeoProposalStore(); $router = $this->router(['admin_user_id'=>1,'csrf_token'=>'token'],$store);
        $document=(new ArticleRepository($this->root))->read('first-note'); $hash=hash('sha256',$document->bodyMarkdown);
        $store->save(new \HolyMD\Geo\GeoProposal(new \HolyMD\Geo\GeoProposalId('metadata-edit'),'first-note',$hash,'metadata',['summary'=>'Old']));
        $bad=$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/metadata-edit/edit',[],['csrf_token'=>'token','value'=>'{bad'])); self::assertSame(422,$bad->status);
        $edited=$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/metadata-edit/edit',[],['csrf_token'=>'token','value'=>'{"summary":"Edited"}'])); self::assertSame(200,$edited->status); self::assertSame(['summary'=>'Edited'],$store->get(new \HolyMD\Geo\GeoProposalId('metadata-edit'))->value);
        $accepted=$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/metadata-edit/accept',[],['csrf_token'=>'token'])); self::assertTrue(json_decode($accepted->body,true,flags:JSON_THROW_ON_ERROR)['accepted']); self::assertSame('accepted',$store->get(new \HolyMD\Geo\GeoProposalId('metadata-edit'))->status);

        $current=(new ArticleRepository($this->root))->read('first-note'); $store->save(new \HolyMD\Geo\GeoProposal(new \HolyMD\Geo\GeoProposalId('entities-edit'),'first-note',hash('sha256',$current->bodyMarkdown),'entities',['Old']));
        self::assertSame(200,$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/entities-edit/edit',[],['csrf_token'=>'token','value'=>'["Ada","PHP"]']))->status);
        self::assertSame(['Ada','PHP'],$store->get(new \HolyMD\Geo\GeoProposalId('entities-edit'))->value);
    }
    public function test_edit_preserves_plain_string_values_for_every_string_shaped_proposal(): void {
        $store = new InMemoryGeoProposalStore(); $router = $this->router(['admin_user_id'=>1,'csrf_token'=>'token'],$store);
        $document=(new ArticleRepository($this->root))->read('first-note'); $hash=hash('sha256',$document->bodyMarkdown);
        foreach (['summary', 'metadata', 'hierarchy', 'sources', 'internal_links', 'alt_text', 'structured_data'] as $type) {
            $id = new \HolyMD\Geo\GeoProposalId('plain-' . str_replace('_', '-', $type));
            $store->save(new \HolyMD\Geo\GeoProposal($id, 'first-note', $hash, $type, 'Original text'));
            $response = $router->dispatch(new ServerRequest('POST', '/admin/geo/proposals/' . $id->value . '/edit', [], ['csrf_token'=>'token','value'=>'Edited text']));
            self::assertSame(200, $response->status, $type);
            self::assertSame('Edited text', $store->get($id)->value, $type);
        }
    }
    private function router(array $session, GeoProposalStore $store): Router {
        $articles = new ArticleRepository($this->root); $controller = new ArticleController($articles, new VersionService($this->root . '/versions'), new AdminGuard($session), new Csrf($session));
        $client = new class implements AiClient { public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { return new AiResponse('{"proposals":[{"type":"summary","value":"Summary"}],"findings":[]}'); } };
        return Router::admin($controller, new GeoController($articles, new GeoReviewService($client), $store, new AdminGuard($session), new Csrf($session)));
    }
}
