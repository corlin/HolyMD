<?php

declare(strict_types=1);

namespace HolyMD\Tests\EndToEnd;

use HolyMD\Admin\ArticleController;
use HolyMD\Admin\VersionService;
use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\AiResponse;
use HolyMD\Geo\GeoPrompt;
use HolyMD\Geo\GeoReviewService;
use HolyMD\Geo\InMemoryGeoProposalStore;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use HolyMD\Publish\ArticleId;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GeoBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-e2e-geo-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        file_put_contents(
            $this->root . '/geo-boundary.md',
            "---\ntitle: Boundary Article\nslug: geo-boundary\ndate: 2026-08-12\nstatus: draft\n---\n# Original Body\n\nThis prose must never be altered by the AI system.\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    // ---- Prompt-level boundary ----

    public function test_system_prompt_forbids_prose_generation(): void
    {
        $prompt = GeoPrompt::system();

        self::assertStringContainsString('DO NOT draft, rewrite, paraphrase', $prompt);
        self::assertStringContainsString('Do not continue or return replacement body Markdown', $prompt);
        self::assertStringContainsString('Never use type "body"', $prompt);
        self::assertStringContainsString('Never invent source URLs', $prompt);
        self::assertStringContainsString('question and answer', $prompt);
        self::assertStringContainsString('existing site-relative or HTTPS URLs', $prompt);
    }

    // ---- Review-level boundary ----

    public function test_review_rejects_body_type_proposals(): void
    {
        $document = $this->loadDocument();

        $this->expectException(InvalidArgumentException::class);
        (new GeoReviewService(new BoundaryAiClient(
            '{"proposals":[{"type":"body","value":"Here is your rewritten article"}],"findings":[]}',
        )))->review($document);
    }

    public function test_review_rejects_forbidden_keys_in_allowed_type_proposals(): void
    {
        $document = $this->loadDocument();
        $forbidden = ['body', 'content', 'markdown', 'body_markdown', 'rewrite'];
        $rejected = 0;

        foreach ($forbidden as $key) {
            $json = json_encode([
                'proposals' => [['type' => 'metadata', 'value' => [$key => 'smuggled prose']]],
                'findings' => [],
            ], JSON_THROW_ON_ERROR);

            try {
                (new GeoReviewService(new BoundaryAiClient($json)))->review($document);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        self::assertSame(count($forbidden), $rejected, 'Every forbidden key must be rejected.');
    }

    // ---- Save-boundary checks (accept no longer writes files; the draft save pipeline does) ----

    public function test_save_pipeline_modifies_only_front_matter_keys(): void
    {
        $repository = new ArticleRepository($this->root);
        $original = $repository->read('geo-boundary');
        $bodyHashBefore = hash('sha256', $original->bodyMarkdown);
        $frontMatterBefore = $original->frontMatter->all();

        $session = ['admin_user_id' => 1, 'csrf_token' => 'token'];
        $controller = new ArticleController($repository, new VersionService($this->root . '/versions'), new AdminGuard($session), new Csrf($session));
        $response = (new Router($controller))->dispatch(new ServerRequest('POST', '/admin/articles/geo-boundary/draft', [], [
            'csrf_token' => 'token',
            'expected_checksum' => hash('sha256', (string) file_get_contents($this->root . '/geo-boundary.md')),
            'title' => (string) $frontMatterBefore['title'],
            'date' => (string) $frontMatterBefore['date'],
            'body' => $original->bodyMarkdown,
            'summary' => 'Factual summary.',
            'topics' => "Security\n",
        ]));

        self::assertSame(200, $response->status);
        $saved = $repository->read('geo-boundary');

        // Body byte-for-byte unchanged
        self::assertSame($original->bodyMarkdown, $saved->bodyMarkdown);
        self::assertSame($bodyHashBefore, hash('sha256', $saved->bodyMarkdown));

        // Only expected keys changed
        self::assertSame('Factual summary.', $saved->frontMatter->get('summary'));
        self::assertSame(['Security'], $saved->frontMatter->get('topics'));

        // Original keys preserved
        self::assertSame($frontMatterBefore['title'], $saved->frontMatter->get('title'));
        self::assertSame($frontMatterBefore['slug'], $saved->frontMatter->get('slug'));
        self::assertSame($frontMatterBefore['date'], $saved->frontMatter->get('date'));
    }

    // ---- Full cycle integration ----

    public function test_full_geo_cycle_preserves_body_through_review_accept_and_publish(): void
    {
        // Setup a publishable directory structure
        $pubRoot = sys_get_temp_dir() . '/holymd-e2e-geo-pub-' . bin2hex(random_bytes(6));
        mkdir($pubRoot . '/articles', 0777, true);
        mkdir($pubRoot . '/public/site', 0777, true);
        file_put_contents($pubRoot . '/public/site/index.html', 'old');
        file_put_contents($pubRoot . '/public/site/.holymd-manifest.json', '{}');
        file_put_contents($pubRoot . '/public/.holymd-current', "site\n");

        $bodyText = "# Deep Dive\n\nThis body is the single source of truth and must survive unchanged.\n";
        file_put_contents(
            $pubRoot . '/articles/deep-dive.md',
            "---\ntitle: Deep Dive\nslug: deep-dive\ndate: 2026-08-12\nstatus: draft\n---\n" . $bodyText,
        );

        try {
            $repository = new ArticleRepository($pubRoot . '/articles');
            $original = $repository->read('deep-dive');
            $bodyHashOriginal = hash('sha256', $original->bodyMarkdown);

            // Step 1: GEO review
            $aiJson = json_encode([
                'proposals' => [['type' => 'summary', 'value' => 'A deep dive article.']],
                'findings' => [],
            ], JSON_THROW_ON_ERROR);
            $store = new InMemoryGeoProposalStore();
            $review = (new GeoReviewService(new BoundaryAiClient($aiJson), $store))->review($original);
            self::assertSame($bodyHashOriginal, $review->bodyHash, 'Review must record the original body hash.');

            // Step 2: Apply the proposal through the editor save path (fill + autosave)
            $accepted = $original->withFrontMatter($original->frontMatter->with('summary', 'A deep dive article.'));
            self::assertSame($bodyHashOriginal, hash('sha256', $accepted->bodyMarkdown), 'Body hash must be unchanged after filling the proposal.');

            // Step 3: Publish
            $ready = $accepted->withFrontMatter($accepted->frontMatter->with('status', 'published'));
            $repository->write($ready);

            $service = new PublishService(
                $repository,
                new StaticBuilder(),
                new AtomicPublicTree(),
                $pubRoot . '/public/.holymd-current',
                'GEO Boundary E2E',
                'https://geo-boundary.test',
                'Boundary Author',
                'About the boundary author.',
                false,
                $pubRoot . '/audit',
            );
            $service->publish(new ArticleId('deep-dive'));

            // Step 4: Final body check
            $final = $repository->read('deep-dive');
            self::assertSame($bodyHashOriginal, hash('sha256', $final->bodyMarkdown), 'Body hash must survive the entire GEO → publish cycle.');
            self::assertSame($original->bodyMarkdown, $final->bodyMarkdown, 'Body text must be byte-for-byte identical after the full cycle.');
        } finally {
            $this->removeDirectory($pubRoot);
        }
    }

    private function loadDocument(): ArticleDocument
    {
        return (new ArticleRepository($this->root))->read('geo-boundary');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childPath = $path . '/' . $child;
            is_dir($childPath) && !is_link($childPath) ? $this->removeDirectory($childPath) : unlink($childPath);
        }
        rmdir($path);
    }
}

/** @internal */
final readonly class BoundaryAiClient implements AiClient
{
    public function __construct(private string $json)
    {
    }

    public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse
    {
        return new AiResponse($this->json);
    }
}
