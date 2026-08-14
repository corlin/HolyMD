<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\VersionService;
use HolyMD\Admin\VersionId;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use PHPUnit\Framework\TestCase;

final class VersionServiceTest extends TestCase
{
    private string $root;
    private VersionService $service;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-versions-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->service = new VersionService($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function document(string $slug, string $body = "Body\n"): ArticleDocument
    {
        return new ArticleDocument($slug, 'Article ' . $slug, $body, new FrontMatter(['title' => 'Article ' . $slug, 'slug' => $slug, 'date' => '2026-08-12']), '/' . $slug);
    }

    public function test_confirmed_publication_writes_a_slug_index(): void
    {
        $id = $this->publish($this->document('first'));

        self::assertFileExists($this->root . '/index.json');
        $index = json_decode((string) file_get_contents($this->root . '/index.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$id->value], $index['first']);
    }

    public function test_list_reads_from_the_index_and_orders_descending(): void
    {
        $first = $this->publish($this->document('first', "One\n"));
        $second = $this->publish($this->document('first', "Two\n"));
        $other = $this->publish($this->document('other'));

        self::assertSame([$second->value, $first->value], array_map(static fn ($id) => $id->value, $this->service->list('first')));
        self::assertSame([$other->value], array_map(static fn ($id) => $id->value, $this->service->list('other')));
    }

    public function test_list_does_not_treat_unindexed_snapshot_files_as_published_versions(): void
    {
        $id = $this->service->capturePublicationInput($this->document('first'));
        $this->service->stagePublished($id);

        self::assertSame([], $this->service->list('first'));
    }

    public function test_repeated_publication_confirmation_does_not_duplicate_index_entries(): void
    {
        $id = $this->publish($this->document('first'));
        $this->service->confirmPublished('first', $id);

        $index = json_decode((string) file_get_contents($this->root . '/index.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $index['first']);
    }

    public function test_geo_review_input_is_immutable_but_not_a_content_version(): void
    {
        $id = $this->service->captureReviewInput($this->document('first'));

        self::assertSame("Body\n", $this->service->restoreReviewInput($id, 'first')->bodyMarkdown);
        self::assertSame([], $this->service->list('first'));
        self::assertFileDoesNotExist($this->root . '/' . $id->value . '.md');
        self::assertFileExists($this->root . '/review-inputs/' . $id->value . '.md');
    }

    public function test_publication_input_becomes_a_content_version_only_when_confirmed(): void
    {
        $id = $this->service->capturePublicationInput($this->document('first'));

        self::assertSame([], $this->service->list('first'));
        self::assertSame("Body\n", $this->service->restorePublicationInput($id, 'first')->bodyMarkdown);

        $this->service->stagePublished($id);
        self::assertSame([], $this->service->list('first'));

        $this->service->confirmPublished('first', $id);
        self::assertSame([$id->value], array_map(static fn (VersionId $version): string => $version->value, $this->service->list('first')));
        self::assertSame("Body\n", $this->service->restore($id, 'first')->bodyMarkdown);
    }

    public function test_indexed_missing_files_are_filtered_from_listings(): void
    {
        $id = $this->publish($this->document('first'));
        unlink($this->root . '/' . $id->value . '.md');

        self::assertSame([], $this->service->list('first'));
    }

    public function test_pin_published_versions_creates_stable_pointers_without_pinning_drafts(): void
    {
        mkdir($this->root . '/articles');
        file_put_contents($this->root . '/articles/public.md', "---\ntitle: Public\nslug: public\ndate: 2026-08-12\nstatus: published\n---\nPublic body\n");
        file_put_contents($this->root . '/articles/draft.md', "---\ntitle: Draft\nslug: draft\ndate: 2026-08-12\nstatus: draft\n---\nDraft body\n");
        $repository = new ArticleRepository($this->root . '/articles');

        self::assertSame(1, $this->service->pinPublished($repository));

        $public = $repository->read('public');
        $pointer = $public->frontMatter->get('published_version');
        self::assertIsString($pointer);
        self::assertSame("Public body\n", $this->service->restore(new \HolyMD\Admin\VersionId($pointer), 'public')->bodyMarkdown);
        self::assertNull($repository->read('draft')->frontMatter->get('published_version'));
        self::assertSame(0, $this->service->pinPublished($repository));
    }

    private function publish(ArticleDocument $document): VersionId
    {
        $id = $this->service->capturePublicationInput($document);
        $this->service->stagePublished($id);
        $this->service->confirmPublished($document->slug, $id);
        return $id;
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
