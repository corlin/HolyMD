<?php

declare(strict_types=1);

namespace HolyMD\Tests\EndToEnd;

use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\AiResponse;
use HolyMD\Geo\GeoReviewService;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
use PHPUnit\Framework\TestCase;

final class PublishFlowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-e2e-flow-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/articles', 0777, true);
        mkdir($this->root . '/public/site', 0777, true);
        file_put_contents($this->root . '/public/site/index.html', 'previous site');
        file_put_contents($this->root . '/public/site/.holymd-manifest.json', '{"build":"previous"}');
        file_put_contents($this->root . '/public/.holymd-current', "site\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_draft_through_geo_review_and_acceptance_to_static_publish(): void
    {
        // --- Seed a draft article ---
        $bodyText = "# Understanding PHP 8.4\n\nPHP 8.4 introduces property hooks, asymmetric visibility, and a new DOM API.\n\nSources should be cited where available.\n";
        file_put_contents(
            $this->root . '/articles/php-84-features.md',
            "---\ntitle: Understanding PHP 8.4\nslug: php-84-features\ndate: 2026-08-12\nstatus: draft\n---\n" . $bodyText,
        );

        $repository = new ArticleRepository($this->root . '/articles');
        $original = $repository->read('php-84-features');
        $bodyHashBefore = hash('sha256', $original->bodyMarkdown);

        // --- GEO review with fake AI ---
        $aiJson = json_encode([
            'proposals' => [
                ['type' => 'summary', 'value' => 'An overview of key PHP 8.4 features.'],
                ['type' => 'entities', 'value' => ['PHP', 'Property Hooks', 'DOM API']],
            ],
            'findings' => ['Consider adding publication date context.'],
        ], JSON_THROW_ON_ERROR);

        $review = (new GeoReviewService(new FakeAiClient($aiJson)))->review($original);

        self::assertSame($bodyHashBefore, $review->bodyHash);
        self::assertCount(2, $review->proposals);

        // --- Apply the summary proposal through the editor save path (fill + autosave) ---
        $summaryProposal = $review->proposals[0];
        $accepted = $original->withFrontMatter($original->frontMatter->with('summary', (string) $summaryProposal->value));

        self::assertSame('An overview of key PHP 8.4 features.', $accepted->frontMatter->get('summary'));
        self::assertSame($original->bodyMarkdown, $accepted->bodyMarkdown, 'Body must be byte-for-byte identical after acceptance.');
        self::assertSame($bodyHashBefore, hash('sha256', $accepted->bodyMarkdown));

        // --- Set status to published and write ---
        $published = $accepted->withFrontMatter($accepted->frontMatter->with('status', 'published'));
        $repository->write($published);

        // --- Publish via PublishService ---
        $result = $this->publishService()->publish('php-84-features');

        self::assertSame(1, $result->manifest->articleCount);

        // --- Assert generated static files ---
        $currentRoot = $this->released();
        self::assertFileExists($currentRoot . '/articles/php-84-features/index.html');

        $articleHtml = (string) file_get_contents($currentRoot . '/articles/php-84-features/index.html');
        self::assertStringContainsString('Understanding PHP 8.4', $articleHtml);

        $feedJson = (string) file_get_contents($currentRoot . '/feed.json');
        self::assertStringContainsString('php-84-features', $feedJson);

        $rss = (string) file_get_contents($currentRoot . '/rss.xml');
        self::assertStringContainsString('php-84-features', $rss);

        $sitemap = (string) file_get_contents($currentRoot . '/sitemap.xml');
        self::assertStringContainsString('php-84-features', $sitemap);

        // --- Body hash unchanged throughout ---
        $final = $repository->read('php-84-features');
        self::assertSame($bodyHashBefore, hash('sha256', $final->bodyMarkdown), 'Body hash must survive the entire publish flow.');
    }

    public function test_withdrawn_article_vanishes_from_all_discovery_files_after_republish(): void
    {
        file_put_contents(
            $this->root . '/articles/active.md',
            "---\ntitle: Active Article\nslug: active\ndate: 2026-08-12\nstatus: published\n---\nStays visible.\n",
        );
        file_put_contents(
            $this->root . '/articles/removed.md',
            "---\ntitle: Removed Article\nslug: removed\ndate: 2026-08-11\nstatus: withdrawn\n---\nGone from discovery.\n",
        );

        $this->publishService()->publish('active');

        $currentRoot = $this->released();
        $feedJson = (string) file_get_contents($currentRoot . '/feed.json');
        $rss = (string) file_get_contents($currentRoot . '/rss.xml');
        $sitemap = (string) file_get_contents($currentRoot . '/sitemap.xml');

        self::assertStringContainsString('active', $feedJson);
        self::assertStringNotContainsString('removed', $feedJson);
        self::assertStringContainsString('active', $rss);
        self::assertStringNotContainsString('removed', $rss);
        self::assertStringContainsString('active', $sitemap);
        self::assertStringNotContainsString('removed', $sitemap);
    }

    private function released(): string
    {
        $pointer = $this->root . '/public/.holymd-current';
        $resolved = realpath($pointer);
        if (($resolved === false || !is_dir($resolved)) && is_file($pointer)) {
            $target = trim((string) file_get_contents($pointer));
            $resolved = realpath(dirname($pointer) . '/' . $target);
        }
        if ($resolved === false || !is_dir($resolved)) {
            self::fail('Release pointer does not resolve.');
        }
        return $resolved;
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            new ArticleRepository($this->root . '/articles'),
            new StaticBuilder(),
            new AtomicPublicTree(),
            $this->root . '/public/.holymd-current',
            new PublicationSettings('HolyMD E2E', 'https://e2e.test', 'E2E Author', 'About the E2E author.', true),
            $this->root . '/audit',
        );
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
final class FakeAiClient implements AiClient
{
    public string $systemPrompt = '';
    public string $articleMarkdown = '';

    public function __construct(private readonly string $json)
    {
    }

    public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse
    {
        $this->systemPrompt = $systemPrompt;
        $this->articleMarkdown = $articleMarkdown;

        return new AiResponse($this->json);
    }
}
