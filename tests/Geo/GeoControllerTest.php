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
        self::assertSame(401, $this->router([], new InMemoryGeoProposalStore())->dispatch(new ServerRequest('GET', '/admin/articles/first-note/geo/review'))->status);
    }

    public function test_admin_javascript_polls_queued_review_status_before_mapping_proposals(): void {
        $javascript = (string) file_get_contents(__DIR__ . '/../../public/assets/admin.js');
        self::assertStringContainsString('if(x.queued)', $javascript);
        self::assertStringContainsString("x.status==='completed'", $javascript);
        self::assertStringContainsString('await poll()', $javascript);
        self::assertStringContainsString('attempt<60', $javascript);
        self::assertStringContainsString('waiting for Cron worker', $javascript);
        self::assertStringContainsString('Refresh GEO status', $javascript);
        self::assertStringNotContainsString('x.proposals.map', $javascript);
        self::assertStringContainsString('dataset.proposalValue=JSON.stringify(p.value)', $javascript);
        self::assertStringContainsString('resumeStatus()', $javascript);
        self::assertStringContainsString("x.status==='running'", $javascript);
        self::assertStringContainsString("x.status==='failed'", $javascript);
    }
    public function test_edit_decodes_structured_metadata_and_rejects_malformed_json_before_accept(): void {
        $store = new InMemoryGeoProposalStore(); $router = $this->router(['admin_user_id'=>1,'csrf_token'=>'token'],$store);
        $document=(new ArticleRepository($this->root))->read('first-note'); $hash=hash('sha256',$document->bodyMarkdown);
        $store->save(new \HolyMD\Geo\GeoProposal(new \HolyMD\Geo\GeoProposalId('metadata-edit'),'first-note',$hash,'metadata',['summary'=>'Old']));
        $bad=$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/metadata-edit/edit',[],['csrf_token'=>'token','value'=>'{bad'])); self::assertSame(422,$bad->status);
        $edited=$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/metadata-edit/edit',[],['csrf_token'=>'token','value'=>'{"summary":"Edited"}'])); self::assertSame(200,$edited->status); self::assertSame(['summary'=>'Edited'],$store->get(new \HolyMD\Geo\GeoProposalId('metadata-edit'))->value);
        $accepted=$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/metadata-edit/accept',[],['csrf_token'=>'token'])); self::assertSame('Edited',json_decode($accepted->body,true,flags:JSON_THROW_ON_ERROR)['frontMatter']['summary']);

        $current=(new ArticleRepository($this->root))->read('first-note'); $store->save(new \HolyMD\Geo\GeoProposal(new \HolyMD\Geo\GeoProposalId('entities-edit'),'first-note',hash('sha256',$current->bodyMarkdown),'entities',['Old']));
        self::assertSame(200,$router->dispatch(new ServerRequest('POST','/admin/geo/proposals/entities-edit/edit',[],['csrf_token'=>'token','value'=>'["Ada","PHP"]']))->status);
        self::assertSame(['Ada','PHP'],$store->get(new \HolyMD\Geo\GeoProposalId('entities-edit'))->value);
    }
    private function router(array $session, GeoProposalStore $store): Router {
        $articles = new ArticleRepository($this->root); $controller = new ArticleController($articles, new VersionService($this->root . '/versions'), new AdminGuard($session), new Csrf($session));
        $client = new class implements AiClient { public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { return new AiResponse('{"proposals":[{"type":"summary","value":"Summary"}],"findings":[]}'); } };
        return Router::admin($controller, new GeoController($articles, new GeoReviewService($client), $store, new AdminGuard($session), new Csrf($session)));
    }
}
