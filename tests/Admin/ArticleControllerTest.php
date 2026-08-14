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
        mkdir($this->root . '/media', 0777, true);
        mkdir($this->root . '/public/site', 0777, true);
        file_put_contents($this->root . '/public/site/index.html', 'legacy');
        file_put_contents($this->root . '/public/.holymd-current', "site\n");
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

    public function test_new_article_creates_safe_markdown_without_a_published_version_then_redirects_to_edit(): void
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
        self::assertCount(0, (new VersionService($this->root . '/versions'))->list('fresh-note-2026'));
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

    public function test_draft_save_writes_markdown_without_creating_a_version_and_round_trips_the_body(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'Revised note',
            'date' => '2026-08-12',
            'body' => "# Exact body\n\nTrailing spaces  \n",
            'expected_checksum' => $checksum,
            'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('versionId', $payload);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['checksum']);
        self::assertSame("# Exact body\n\nTrailing spaces  \n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
        self::assertSame(0, count(glob($this->root . '/versions/*.md') ?: []));
    }

    public function test_editor_exposes_metadata_fields_for_editing(): void
    {
        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('GET', '/admin/articles/first-note/edit'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="summary"', $response->body);
        self::assertStringContainsString('name="structured_data"', $response->body);
        self::assertStringContainsString('data-metadata-input', $response->body);
        self::assertStringContainsString('name="alt_text"', $response->body);
        self::assertStringContainsString('name="hierarchy"', $response->body);
        self::assertStringContainsString('name="internal_links"', $response->body);
        self::assertStringContainsString('data-geo-field="summary"', $response->body);
        self::assertStringContainsString('data-geo-catchall', $response->body);
    }

    public function test_draft_save_round_trips_metadata_fields(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body',
            'summary' => 'A short summary.', 'topics' => "Health\nResearch", 'sources' => 'https://example.test/evidence',
            'structured_data' => '{"@type": "MedicalWebPage"}',
            'expected_checksum' => $checksum, 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        $article = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertSame('A short summary.', $article->frontMatter->get('summary'));
        self::assertSame(['Health', 'Research'], $article->frontMatter->get('topics'));
        self::assertSame(['https://example.test/evidence'], $article->frontMatter->get('sources'));
        self::assertSame(['@type' => 'MedicalWebPage'], $article->frontMatter->get('structured_data'));
        $onDisk = (string) file_get_contents($this->root . '/articles/first-note.md');
        self::assertStringContainsString("topics:\n  - Health\n  - Research", $onDisk);
    }

    public function test_draft_save_rejects_invalid_metadata_values(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));
        $base = ['title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body', 'expected_checksum' => $checksum, 'csrf_token' => 'expected-token'];

        $invalidJson = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['structured_data' => '{broken']));
        self::assertSame(422, $invalidJson->status);
        self::assertStringContainsString('valid JSON', $invalidJson->body);

        $badSource = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['sources' => 'not a url']));
        self::assertSame(422, $badSource->status);
        self::assertStringContainsString('invalid citation URL', $badSource->body);

        self::assertStringNotContainsString('summary', (string) file_get_contents($this->root . '/articles/first-note.md'));
    }

    public function test_draft_save_clears_emptied_metadata_keys(): void
    {
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nsummary: Old summary\n---\nOriginal body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body',
            'summary' => '', 'topics' => "  \n", 'expected_checksum' => $checksum, 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        $article = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertArrayNotHasKey('summary', $article->frontMatter->all());
        self::assertArrayNotHasKey('topics', $article->frontMatter->all());
    }

    public function test_draft_save_round_trips_free_form_and_json_typed_metadata_fields(): void
    {
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nalt_text:\n  - Old alt\nhierarchy:\n  h1: Overview\ninternal_links:\n  - Old link\nfaq:\n  - question: Old Q\n    answer: Old A\n---\nOriginal body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body',
            'alt_text' => "First alt\nSecond alt",
            'hierarchy' => '{"h1":"Overview","h2":"Details"}',
            'internal_links' => "/articles/guide/\nhttps://example.test/notes/",
            'faq' => "[\n  {\n    \"question\": \"New Q\",\n    \"answer\": \"New A\"\n  }\n]",
            'expected_checksum' => $checksum, 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        $article = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertSame(['First alt', 'Second alt'], $article->frontMatter->get('alt_text'));
        self::assertSame(['h1' => 'Overview', 'h2' => 'Details'], $article->frontMatter->get('hierarchy'));
        self::assertSame(['/articles/guide/', 'https://example.test/notes/'], $article->frontMatter->get('internal_links'));
        self::assertSame([['question' => 'New Q', 'answer' => 'New A']], $article->frontMatter->get('faq'));
    }

    public function test_edit_page_renders_faq_arrays_as_json_not_Array(): void
    {
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nfaq:\n  - question: Q\n    answer: A\n---\nOriginal body\n");
        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('GET', '/admin/articles/first-note/edit'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('&quot;question&quot;', $response->body);
        self::assertStringNotContainsString('>Array<', $response->body);
    }

    public function test_first_structured_faq_save_preserves_question_answer_objects(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));
        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body',
            'faq' => '[{"question":"What is GEO?","answer":"Metadata optimization."}]',
            'expected_checksum' => $checksum, 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        self::assertSame(
            [['question' => 'What is GEO?', 'answer' => 'Metadata optimization.']],
            (new ArticleRepository($this->root . '/articles'))->read('first-note')->frontMatter->get('faq'),
        );
    }

    public function test_draft_save_rejects_malformed_faq_json_when_faq_is_array_typed(): void
    {
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nfaq:\n  - question: Q\n    answer: A\n---\nOriginal body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));
        $base = ['title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body', 'expected_checksum' => $checksum, 'csrf_token' => 'expected-token'];

        $broken = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['faq' => '{broken']));
        self::assertSame(422, $broken->status);
        self::assertStringContainsString('valid JSON', $broken->body);

        $notArray = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['faq' => '"just a string"']));
        self::assertSame(422, $notArray->status);
        self::assertStringContainsString('JSON array or object', $notArray->body);
    }

    public function test_draft_save_rejects_forbidden_keys_in_free_form_json(): void
    {
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\nhierarchy:\n  h1: Overview\n---\nOriginal body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));
        $base = ['title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body', 'expected_checksum' => $checksum, 'csrf_token' => 'expected-token'];

        $forbidden = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['hierarchy' => '{"body":"smuggled prose"}']));
        self::assertSame(422, $forbidden->status);
        self::assertStringContainsString('invalid hierarchy metadata', $forbidden->body);

        $safeString = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['alt_text' => 'body text as a plain description']));
        self::assertSame(200, $safeString->status);
    }

    public function test_draft_save_rejects_a_redirect_collision_with_a_published_route(): void
    {
        file_put_contents($this->root . '/articles/published.md', "---\ntitle: Published\nslug: published\ndate: 2026-08-11\nstatus: published\n---\nLive\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => 'Original body', 'previous_slugs' => 'published',
            'expected_checksum' => $checksum, 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('collides with a published route', $response->body);
    }

    public function test_create_accepts_metadata_fields(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/new', [], [
            'title' => 'With metadata', 'slug' => 'with-metadata', 'date' => '2026-08-12', 'body' => "Body\n",
            'summary' => 'Summary.', 'topics' => "One\nTwo", 'csrf_token' => 'expected-token',
        ]));

        self::assertSame(303, $response->status);
        $article = (new ArticleRepository($this->root . '/articles'))->read('with-metadata');
        self::assertSame('Summary.', $article->frontMatter->get('summary'));
        self::assertSame(['One', 'Two'], $article->frontMatter->get('topics'));
    }

    public function test_draft_save_rejects_a_stale_editor_checksum_without_overwriting_newer_content(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $originalChecksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));
        $base = ['title' => 'First note', 'date' => '2026-08-12', 'expected_checksum' => $originalChecksum, 'csrf_token' => 'expected-token'];

        $first = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['body' => "Newer body\n"]));
        $stale = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], $base + ['body' => "Older body\n"]));

        self::assertSame(200, $first->status);
        self::assertSame(409, $stale->status);
        self::assertStringContainsString('another editor session', $stale->body);
        self::assertSame("Newer body\n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
    }

    public function test_editor_requests_server_rendered_markdown_for_live_preview(): void
    {
        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('GET', '/admin/articles/first-note/edit'));
        $javascript = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.js');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="markdown-preview"', $response->body);
        self::assertStringContainsString("base + '/admin/markdown/preview'", $javascript);
        self::assertStringContainsString('preview.innerHTML', $javascript);
        self::assertStringNotContainsString('preview.textContent=body.value', $javascript);
    }

    public function test_restore_is_authorized_and_restores_a_snapshot(): void
    {
        $versions = new VersionService($this->root . '/versions');
        $document = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        $version = $versions->capturePublicationInput($document);
        $versions->stagePublished($version);
        $versions->confirmPublished('first-note', $version);
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\n---\nChanged body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/restore/' . $version->value, [], ['csrf_token' => 'expected-token']));

        self::assertSame(303, $response->status);
        self::assertSame("Original body\n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
    }

    public function test_identical_publication_input_reuses_the_same_candidate_without_creating_a_version(): void
    {
        $versions = new VersionService($this->root . '/versions');
        $document = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertSame($versions->capturePublicationInput($document)->value, $versions->capturePublicationInput($document)->value);
        self::assertCount(1, glob($this->root . '/versions/publish-inputs/*.md') ?: []);
        self::assertSame([], $versions->list('first-note'));
    }

    public function test_publish_is_an_authorized_csrf_protected_real_publication_action(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', [], ['csrf_token' => 'expected-token']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Article published', $response->body);
        self::assertStringContainsString('href="/articles/first-note/"', $response->body);
        self::assertFileExists($this->publishedRoot() . '/articles/first-note/index.html');
        $published = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $published->frontMatter->get('published_version'));
        self::assertCount(1, (new VersionService($this->root . '/versions'))->list('first-note'));
    }

    public function test_only_a_successful_publish_advances_content_version_history(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        self::assertSame(200, $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', [], ['csrf_token' => 'expected-token']))->status);

        $repository = new ArticleRepository($this->root . '/articles');
        $versions = new VersionService($this->root . '/versions');
        $firstPublishedVersion = $repository->read('first-note')->frontMatter->get('published_version');
        self::assertCount(1, $versions->list('first-note'));

        $checksum = hash('sha256', $repository->read('first-note')->serialize());
        $saved = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/draft', [], [
            'title' => 'First note', 'date' => '2026-08-12', 'body' => "Saved after publication\n",
            'expected_checksum' => $checksum, 'csrf_token' => 'expected-token',
        ]));
        self::assertSame(200, $saved->status);
        self::assertCount(1, $versions->list('first-note'));
        self::assertSame($firstPublishedVersion, $repository->read('first-note')->frontMatter->get('published_version'));

        self::assertSame(200, $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', [], ['csrf_token' => 'expected-token']))->status);
        self::assertCount(2, $versions->list('first-note'));
        self::assertNotSame($firstPublishedVersion, $repository->read('first-note')->frontMatter->get('published_version'));
    }

    public function test_published_editor_can_update_public_with_the_latest_submitted_markdown(): void
    {
        file_put_contents($this->root . '/articles/first-note.md', "---\ntitle: Public note\nslug: first-note\ndate: 2026-08-12\nstatus: published\n---\nOld public body\n");
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $editor = $router->dispatch(new ServerRequest('GET', '/admin/articles/first-note/edit'));
        self::assertStringContainsString('Update public', $editor->body);
        self::assertStringContainsString('id="publication-form"', $editor->body);
        self::assertStringContainsString('name="body"', $editor->body);

        $checksum = hash('sha256', (string) file_get_contents($this->root . '/articles/first-note.md'));
        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', [], [
            'title' => 'Updated public note',
            'date' => '2026-08-13',
            'body' => "Latest submitted **Markdown**.\n",
            'expected_checksum' => $checksum,
            'csrf_token' => 'expected-token',
        ]));

        self::assertSame(200, $response->status);
        $saved = (new ArticleRepository($this->root . '/articles'))->read('first-note');
        self::assertSame('Updated public note', $saved->title);
        self::assertSame("Latest submitted **Markdown**.\n", $saved->bodyMarkdown);
        $public = (string) file_get_contents($this->publishedRoot() . '/articles/first-note/index.html');
        self::assertStringContainsString('Updated public note', $public);
        self::assertStringContainsString('<strong>Markdown</strong>', $public);
        self::assertStringNotContainsString('Old public body', $public);
    }

    public function test_article_index_shows_workflow_status_modified_date_and_published_link(): void
    {
        file_put_contents($this->root . '/articles/published.md', "---\ntitle: Public note\nslug: published\ndate: 2026-08-11\nstatus: published\n---\nPublic\n");
        file_put_contents($this->root . '/articles/withdrawn.md', "---\ntitle: Old note\nslug: withdrawn\ndate: 2026-08-10\nstatus: withdrawn\n---\nOld\n");

        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('GET', '/admin/articles'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Draft', $response->body);
        self::assertStringContainsString('Published', $response->body);
        self::assertStringContainsString('Withdrawn', $response->body);
        self::assertStringContainsString('Modified', $response->body);
        self::assertStringContainsString('href="/articles/published/"', $response->body);
        self::assertStringContainsString('New article', $response->body);
    }

    public function test_draft_delete_requires_csrf_and_never_deletes_a_published_article(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        self::assertSame(419, $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/delete'))->status);
        self::assertTrue((new ArticleRepository($this->root . '/articles'))->exists('first-note'));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/delete', [], ['csrf_token' => 'expected-token', 'confirm_slug' => 'first-note']));
        self::assertSame(303, $response->status);
        self::assertFalse((new ArticleRepository($this->root . '/articles'))->exists('first-note'));

        file_put_contents($this->root . '/articles/public.md', "---\ntitle: Public\nslug: public\ndate: 2026-08-12\nstatus: published\n---\nBody\n");
        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/public/delete', [], ['csrf_token' => 'expected-token', 'confirm_slug' => 'public']));
        self::assertSame(422, $response->status);
        self::assertTrue((new ArticleRepository($this->root . '/articles'))->exists('public'));
    }

    public function test_media_library_accepts_valid_images_and_rejects_unsafe_uploads(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $png = $this->root . '/upload.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

        $response = $router->dispatch(new ServerRequest('POST', '/admin/media', [], ['csrf_token' => 'expected-token'], ['image' => ['name' => 'My Image.png', 'tmp_name' => $png, 'size' => filesize($png), 'error' => UPLOAD_ERR_OK]]));
        self::assertSame(303, $response->status);
        $files = glob($this->root . '/media/*.png') ?: [];
        self::assertCount(1, $files);

        $library = $router->dispatch(new ServerRequest('GET', '/admin/media'));
        self::assertStringContainsString('/media/' . basename($files[0]), $library->body);
        self::assertStringContainsString('![](/media/', $library->body);

        $text = $this->root . '/bad.txt';
        file_put_contents($text, 'not an image');
        $response = $router->dispatch(new ServerRequest('POST', '/admin/media', [], ['csrf_token' => 'expected-token'], ['image' => ['name' => '../bad.txt', 'tmp_name' => $text, 'size' => filesize($text), 'error' => UPLOAD_ERR_OK]]));
        self::assertSame(422, $response->status);
    }

    public function test_media_upload_uses_actual_file_size_and_requires_decodable_image(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $fake = $this->root . '/fake.png';
        file_put_contents($fake, "\x89PNG\r\n\x1a\nnot-a-decodable-image");
        $response = $router->dispatch(new ServerRequest('POST', '/admin/media', [], ['csrf_token' => 'expected-token'], ['image' => ['name' => 'fake.png', 'tmp_name' => $fake, 'size' => 1, 'error' => UPLOAD_ERR_OK]]));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('decodable image pixels', $response->body);

        $truncated = $this->root . '/truncated.png';
        file_put_contents($truncated, hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489'));
        $response = $router->dispatch(new ServerRequest('POST', '/admin/media', [], ['csrf_token' => 'expected-token'], ['image' => ['name' => 'truncated.png', 'tmp_name' => $truncated, 'size' => filesize($truncated), 'error' => UPLOAD_ERR_OK]]));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('decodable image pixels', $response->body);

        $empty = $this->root . '/empty.png';
        file_put_contents($empty, '');
        $response = $router->dispatch(new ServerRequest('POST', '/admin/media', [], ['csrf_token' => 'expected-token'], ['image' => ['name' => 'empty.png', 'tmp_name' => $empty, 'size' => 100, 'error' => UPLOAD_ERR_OK]]));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('5 MB', $response->body);
    }

    public function test_settings_page_is_explicitly_read_only_for_environment_managed_identity(): void
    {
        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('GET', '/admin/settings'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Environment-managed', $response->body);
        self::assertStringContainsString('HOLYMD_SITE_LANGUAGE', $response->body);
        self::assertStringContainsString('zh-CN', $response->body);
        self::assertStringContainsString('holymd-build.php', $response->body);
    }

    public function test_settings_post_is_not_a_route(): void
    {
        $response = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token'])->dispatch(new ServerRequest('POST', '/admin/settings', [], ['csrf_token' => 'expected-token']));

        self::assertSame(404, $response->status);
    }

    public function test_publish_rejects_a_missing_csrf_token(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish'));

        self::assertSame(419, $response->status);
    }

    public function test_browser_publication_failures_are_escaped_human_readable_html(): void
    {
        $repository = new ArticleRepository($this->root . '/articles');
        $controller = new ArticleController($repository, new VersionService($this->root . '/versions'), new AdminGuard(['admin_user_id' => 7]), new Csrf(['csrf_token' => 'expected-token']));
        $response = Router::admin($controller)->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', ['ACCEPT' => 'text/html'], ['csrf_token' => 'expected-token']));

        self::assertSame(503, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('Publishing is not configured', $response->body);
        self::assertStringContainsString('href="/admin/articles/first-note/edit"', $response->body);
        self::assertStringNotContainsString('{"error"', $response->body);
    }

    public function test_browser_publication_validation_error_is_escaped_in_html(): void
    {
        $repository = new ArticleRepository($this->root . '/articles');
        $publisher = new PublishService($repository, new StaticBuilder(), new AtomicPublicTree(), $this->root . '/public', '<Bad Site>', 'https://example.invalid', '<script>alert(1)</script>', 'About');
        $controller = new ArticleController($repository, new VersionService($this->root . '/versions'), new AdminGuard(['admin_user_id' => 7]), new Csrf(['csrf_token' => 'expected-token']), $publisher);
        $response = Router::admin($controller)->dispatch(new ServerRequest('POST', '/admin/articles/first-note/publish', ['ACCEPT' => 'text/html'], ['csrf_token' => 'expected-token']));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Publication failed', $response->body);
        self::assertStringNotContainsString('<script>', $response->body);
        self::assertStringContainsString('placeholder domain', $response->body);
    }

    public function test_versions_are_scoped_to_the_current_article_for_listing_and_restore(): void
    {
        file_put_contents($this->root . '/articles/second-note.md', "---\ntitle: Second note\nslug: second-note\ndate: 2026-08-12\n---\nSecond body\n");
        $versions = new VersionService($this->root . '/versions');
        $second = (new ArticleRepository($this->root . '/articles'))->read('second-note');
        $secondVersion = $versions->capturePublicationInput($second);
        $versions->stagePublished($secondVersion);
        $versions->confirmPublished('second-note', $secondVersion);
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);

        self::assertSame([], $versions->list('first-note'));
        $response = $router->dispatch(new ServerRequest('POST', '/admin/articles/first-note/restore/' . $secondVersion->value, [], ['csrf_token' => 'expected-token']));

        self::assertSame(422, $response->status);
        self::assertSame("Original body\n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);
    }

    public function test_authenticated_preview_renders_safe_markdown_html_without_saving(): void
    {
        $router = $this->router(['admin_user_id' => 7, 'csrf_token' => 'expected-token']);
        $response = $router->dispatch(new ServerRequest('POST', '/admin/markdown/preview', [], [
            'csrf_token' => 'expected-token',
            'body' => "## Heading\n\n> **Quoted**\n\n<script>alert(1)</script>",
        ]));

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('<h3>Heading</h3>', $payload['html']);
        self::assertStringContainsString('<blockquote>', $payload['html']);
        self::assertStringContainsString('<strong>Quoted</strong>', $payload['html']);
        self::assertStringNotContainsString('<script', $payload['html']);
        self::assertSame("Original body\n", (new ArticleRepository($this->root . '/articles'))->read('first-note')->bodyMarkdown);

        self::assertSame(419, $router->dispatch(new ServerRequest('POST', '/admin/markdown/preview', [], ['body' => '**No token**']))->status);
        self::assertSame(401, $this->router([])->dispatch(new ServerRequest('POST', '/admin/markdown/preview', [], ['body' => '**No admin**']))->status);
    }

    /** @param array<string, mixed> $session */
    private function router(array $session): Router
    {
        $repository = new ArticleRepository($this->root . '/articles');
        $versions = new VersionService($this->root . '/versions');
        $publisher = new PublishService($repository, new StaticBuilder(), new AtomicPublicTree(), $this->root . '/public/.holymd-current', 'Test publication', 'https://example.test', 'Ada Test', 'About Ada.', false, null, null, null, 'zh-CN', $versions);
        $controller = new ArticleController($repository, $versions, new AdminGuard($session), new Csrf($session), $publisher, null, $this->root . '/media', ['site_name' => 'Test publication', 'site_url' => 'https://example.test', 'author_name' => 'Ada Test', 'about' => 'About Ada.', 'site_language' => 'zh-CN']);
        return Router::admin($controller);
    }

    private function publishedRoot(): string
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
