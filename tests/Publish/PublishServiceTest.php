<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use HolyMD\Admin\VersionService;
use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleRepository;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
use HolyMD\Render\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PDO;

final class PublishServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-publish-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/articles', 0777, true);
        mkdir($this->root . '/public/site', 0777, true);
        file_put_contents($this->root . '/public/index.php', '<?php // admin runtime');
        file_put_contents($this->root . '/public/assets.css', 'admin asset');
        file_put_contents($this->root . '/public/site/index.html', 'previous site');
        file_put_contents($this->root . '/public/site/.holymd-manifest.json', '{"build":"previous"}');
        file_put_contents($this->root . '/public/.holymd-current', "site\n");
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nstatus: draft\n---\nBody\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_publish_and_rebuild_share_one_render_pipeline(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Publish/PublishService.php');

        self::assertSame(1, substr_count($source, 'new BuildInput('));
        self::assertStringContainsString('private function renderSite(', $source);
    }

    public function test_renderer_failure_keeps_live_tree_and_manifest_unchanged(): void
    {
        $service = $this->versionedService(new StaticBuilder(new TemplateRenderer($this->root . '/missing-templates')));

        try {
            $service->publish('first-note');
            self::fail('Expected a renderer failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Template', $exception->getMessage());
        }

        self::assertSame('previous site', file_get_contents($this->root . '/public/site/index.html'));
        self::assertSame('{"build":"previous"}', file_get_contents($this->root . '/public/site/.holymd-manifest.json'));
        self::assertSame('<?php // admin runtime', file_get_contents($this->root . '/public/index.php'));
        self::assertSame('draft', (new ArticleRepository($this->root . '/articles'))->read('first-note')->frontMatter->get('status'));
        self::assertSame([], (new VersionService($this->root . '/versions'))->list('first-note'));
    }

    public function test_publish_generates_slug_redirects_and_excludes_withdrawn_articles_from_discovery(): void
    {
        file_put_contents($this->root . '/articles/renamed.md', "---\ntitle: Renamed\nslug: renamed\ndate: 2026-08-11\nstatus: published\nprevious_slugs:\n  - old-name\n---\nPublished\n");
        file_put_contents($this->root . '/articles/withdrawn.md', "---\ntitle: Withdrawn\nslug: withdrawn\ndate: 2026-08-10\nstatus: withdrawn\n---\nGone\n");

        $result = $this->service()->publish('first-note');

        self::assertSame(2, $result->manifest->articleCount);
        self::assertFileExists($this->released() . '/articles/old-name/index.html');
        self::assertStringContainsString('/articles/renamed/', (string) file_get_contents($this->released() . '/articles/old-name/index.html'));
        $redirects = json_decode((string) file_get_contents($this->released() . '/.holymd-redirects.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/articles/renamed/', $redirects['old-name/']);
        self::assertContains('.holymd-redirects.json', $result->manifest->files);
        self::assertStringNotContainsString('withdrawn', (string) file_get_contents($this->released() . '/feed.json'));
        self::assertStringNotContainsString('withdrawn', (string) file_get_contents($this->released() . '/sitemap.xml'));
        self::assertSame('admin asset', file_get_contents($this->root . '/public/assets.css'));
        self::assertSame('published', (new ArticleRepository($this->root . '/articles'))->read('first-note')->frontMatter->get('status'));
    }

    public function test_publishing_another_article_keeps_the_previous_public_snapshot(): void
    {
        $service = $this->versionedService();
        $service->publish('first-note');

        $repository = new ArticleRepository($this->root . '/articles');
        $published = $repository->read('first-note');
        $repository->write(new \HolyMD\Content\ArticleDocument(
            $published->slug,
            $published->title,
            "Unpublished working copy\n",
            $published->frontMatter,
            $published->sourcePath,
        ));
        file_put_contents($this->root . '/articles/second-note.md', "---\ntitle: Second note\nslug: second-note\ndate: 2026-08-13\nstatus: draft\n---\nSecond body\n");

        $service->publish('second-note');

        $firstPublic = (string) file_get_contents($this->released() . '/articles/first-note/index.html');
        self::assertStringContainsString('Body', $firstPublic);
        self::assertStringNotContainsString('Unpublished working copy', $firstPublic);
    }

    public function test_queued_publish_uses_the_selected_snapshot_without_discarding_newer_work(): void
    {
        $versions = new VersionService($this->root . '/versions');
        $repository = new ArticleRepository($this->root . '/articles');
        $selected = $versions->capturePublicationInput($repository->read('first-note'));
        $working = $repository->read('first-note');
        $repository->write(new \HolyMD\Content\ArticleDocument($working->slug, $working->title, "Newer working body\n", $working->frontMatter, $working->sourcePath));

        $this->versionedService()->publish('first-note', $selected);

        $public = (string) file_get_contents($this->released() . '/articles/first-note/index.html');
        self::assertStringContainsString('Body', $public);
        self::assertStringNotContainsString('Newer working body', $public);
        $saved = $repository->read('first-note');
        self::assertSame("Newer working body\n", $saved->bodyMarkdown);
        self::assertSame($selected, $saved->frontMatter->get('published_version'));
    }

    public function test_geo_score_uses_the_selected_publication_snapshot_not_newer_working_copy(): void
    {
        $versions = new VersionService($this->root . '/versions');
        $repository = new ArticleRepository($this->root . '/articles');
        $selectedDocument = $repository->read('first-note')->withFrontMatter(
            $repository->read('first-note')->frontMatter->with('summary', str_repeat('Detailed summary ', 5))
        );
        $repository->write($selectedDocument);
        $selected = $versions->capturePublicationInput($selectedDocument);
        $repository->write($selectedDocument->withFrontMatter($selectedDocument->frontMatter->without('summary')));
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE geo_scores (slug TEXT, score INTEGER, breakdown TEXT, snapshot_trigger TEXT)');
        $service = new PublishService(
            $repository, new StaticBuilder(), new AtomicPublicTree(), $this->root . '/public/.holymd-current',
            $this->publication(), $this->root . '/audit', versions: $versions, pdo: $pdo,
        );

        $service->publish('first-note', $selected);

        self::assertSame(25, (int) $pdo->query('SELECT score FROM geo_scores')->fetchColumn());
    }

    public function test_geo_score_storage_failure_is_audited_without_reversing_publication(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $service = new PublishService(
            new ArticleRepository($this->root . '/articles'), new StaticBuilder(), new AtomicPublicTree(),
            $this->root . '/public/.holymd-current', $this->publication(), $this->root . '/audit', pdo: $pdo,
        );

        $service->publish('first-note');

        self::assertStringContainsString('Body', (string) file_get_contents($this->released() . '/articles/first-note/index.html'));
        $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->root . '/audit/publish.jsonl', FILE_IGNORE_NEW_LINES));
        self::assertSame('published', $events[0]['status']);
        self::assertSame('geo-score', $events[1]['action']);
        self::assertSame('failed', $events[1]['status']);
        self::assertStringContainsString('geo_scores', $events[1]['error']);
    }

    public function test_preflight_reports_changes_and_warnings_without_mutating_any_publication_state(): void
    {
        $service = $this->versionedService();
        $service->publish('first-note');
        $repository = new ArticleRepository($this->root . '/articles');
        $beforeSource = (string) file_get_contents($this->root . '/articles/first-note.md');
        $beforePublic = hash_file('sha256', $this->released() . '/articles/first-note/index.html');
        $beforeVersions = (new VersionService($this->root . '/versions'))->list('first-note');
        $current = $repository->read('first-note');
        $candidate = new \HolyMD\Content\ArticleDocument(
            $current->slug,
            $current->title,
            "Changed body\n",
            $current->frontMatter->with('summary', str_repeat('Detailed summary ', 5)),
            $current->sourcePath,
        );

        $result = $service->preflight($candidate);

        self::assertSame(hash('sha256', $candidate->serialize()), $result->checksum);
        self::assertSame(5, $result->currentScore);
        self::assertSame(25, $result->candidateScore);
        self::assertContains('body', $result->changes);
        self::assertContains('summary', $result->changes);
        self::assertSame([], $result->blockers);
        self::assertNotEmpty($result->warnings);
        self::assertTrue($result->canPublish());
        self::assertTrue($result->requiresAcknowledgement());
        self::assertSame($beforeSource, file_get_contents($this->root . '/articles/first-note.md'));
        self::assertSame($beforePublic, hash_file('sha256', $this->released() . '/articles/first-note/index.html'));
        self::assertSame($beforeVersions, (new VersionService($this->root . '/versions'))->list('first-note'));
    }

    public function test_preflight_collects_metadata_and_route_collision_blockers(): void
    {
        file_put_contents($this->root . '/articles/other.md', "---\ntitle: Other\nslug: other\ndate: 2026-08-11\nstatus: published\n---\nOther\n");
        $repository = new ArticleRepository($this->root . '/articles');
        $current = $repository->read('first-note');
        $candidate = $current->withFrontMatter(
            $current->frontMatter
                ->with('structured_data', ['headline' => 'Missing type'])
                ->with('previous_slugs', ['other'])
        );

        $result = $this->versionedService()->preflight($candidate);

        self::assertFalse($result->canPublish());
        self::assertStringContainsString('structured data', implode("\n", $result->blockers));
        self::assertStringContainsString('collides', implode("\n", $result->blockers));
    }

    public function test_preflight_warns_when_candidate_geo_score_decreases_from_published_snapshot(): void
    {
        $repository = new ArticleRepository($this->root . '/articles');
        $document = $repository->read('first-note');
        $rich = $document->frontMatter
            ->with('summary', str_repeat('Detailed summary ', 5))
            ->with('structured_data', ['@type' => 'Article'])
            ->with('faq', [['question' => 'Q1', 'answer' => 'A1'], ['question' => 'Q2', 'answer' => 'A2']])
            ->with('entities', ['One', 'Two', 'Three'])
            ->with('topics', ['Notes'])
            ->with('sources', ['https://one.test', 'https://two.test'])
            ->with('internal_links', ['/articles/one/', '/articles/two/']);
        $repository->write($document->withFrontMatter($rich));
        $service = $this->versionedService();
        $service->publish('first-note');
        $published = $repository->read('first-note');
        $weak = $published->frontMatter;
        foreach (['summary', 'structured_data', 'faq', 'entities', 'topics', 'sources', 'internal_links'] as $field) {
            $weak = $weak->without($field);
        }

        $result = $service->preflight($published->withFrontMatter($weak));

        self::assertSame(100, $result->currentScore);
        self::assertSame(5, $result->candidateScore);
        self::assertContains('Candidate GEO score decreases from 100 to 5.', $result->warnings);
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

    private function service(?StaticBuilder $builder = null): PublishService
    {
        return new PublishService(
            new ArticleRepository($this->root . '/articles'),
            $builder ?? new StaticBuilder(),
            new AtomicPublicTree(),
            $this->root . '/public/.holymd-current',
            $this->publication(),
            $this->root . '/audit',
        );
    }

    private function versionedService(?StaticBuilder $builder = null): PublishService
    {
        return new PublishService(
            new ArticleRepository($this->root . '/articles'),
            $builder ?? new StaticBuilder(),
            new AtomicPublicTree(),
            $this->root . '/public/.holymd-current',
            $this->publication(),
            $this->root . '/audit',
            null,
            null,
            new VersionService($this->root . '/versions'),
        );
    }

    public function test_persistence_failure_does_not_expose_a_new_tree(): void
    {
        $service = new PublishService(new ArticleRepository($this->root . '/articles'), new StaticBuilder(), new AtomicPublicTree(), $this->root . '/public/.holymd-current', $this->publication(false), $this->root . '/audit', static function (): void { throw new RuntimeException('disk full'); });

        $this->expectExceptionMessage('disk full');
        try { $service->publish('first-note'); }
        finally { self::assertSame('previous site', file_get_contents($this->root . '/public/site/index.html')); }
    }

    public function test_no_redirect_manifest_is_generated_without_previous_slugs(): void
    {
        $this->service()->publish('first-note');

        self::assertFileDoesNotExist($this->released() . '/.holymd-redirects.json');
    }

    public function test_rejects_invalid_article_metadata_at_publish(): void
    {
        file_put_contents($this->root . '/articles/bad-metadata.md', "---\ntitle: Bad metadata\nslug: bad-metadata\ndate: 2026-08-11\nstatus: published\ntopics:\n  - ''\nsources:\n  - ftp://example.test/file\n---\nBad\n");

        try {
            $this->service()->publish('first-note');
            self::fail('Expected metadata validation to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('invalid citation URL', $exception->getMessage());
            self::assertStringContainsString('invalid topic', $exception->getMessage());
        }

        self::assertSame('previous site', file_get_contents($this->root . '/public/site/index.html'));
    }

    public function test_rejects_redirect_collision_with_a_published_route(): void
    {
        file_put_contents($this->root . '/articles/other.md', "---\ntitle: Other\nslug: other\ndate: 2026-08-11\nstatus: published\nprevious_slugs:\n  - first-note\n---\nOther\n");
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->publish('first-note');
    }

    public function test_rejects_placeholder_public_identity_before_building(): void
    {
        $service = new PublishService(
            new ArticleRepository($this->root . '/articles'), new StaticBuilder(), new AtomicPublicTree(),
            $this->root . '/public/.holymd-current', new PublicationSettings('HolyMD', 'https://example.invalid', 'Author', ''),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('site URL');
        try { $service->publish('first-note'); }
        finally {
            self::assertSame('previous site', file_get_contents($this->root . '/public/site/index.html'));
            self::assertSame('draft', (new ArticleRepository($this->root . '/articles'))->read('first-note')->frontMatter->get('status'));
        }
    }

    public function test_rejects_documented_example_identity_values(): void
    {
        $service = new PublishService(
            new ArticleRepository($this->root . '/articles'), new StaticBuilder(), new AtomicPublicTree(),
            $this->root . '/public/.holymd-current', new PublicationSettings('REPLACE_WITH_PUBLICATION_NAME', 'https://REPLACE_WITH_YOUR_DOMAIN', 'REPLACE_WITH_AUTHOR_NAME', 'REPLACE_WITH_AUTHOR_BIOGRAPHY'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('placeholder');
        $service->publish('first-note');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) return;
        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') continue;
            $childPath = $path . '/' . $child;
            is_dir($childPath) && !is_link($childPath) ? $this->removeDirectory($childPath) : unlink($childPath);
        }
        rmdir($path);
    }

    private function publication(bool $generateLlmsTxt = true): PublicationSettings
    {
        return new PublicationSettings('HolyMD Notes', 'https://example.test', 'Ada Author', 'About Ada.', $generateLlmsTxt);
    }
}
