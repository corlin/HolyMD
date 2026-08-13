<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use HolyMD\Content\FrontMatter;
use HolyMD\Geo\GeoProposal;
use HolyMD\Geo\GeoProposalId;
use HolyMD\Geo\InMemoryGeoProposalStore;
use HolyMD\Geo\ProposalAcceptance;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProposalAcceptanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-geo-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/first-note.md', "---\ntitle: First note\nslug: first-note\ndate: 2026-08-12\n---\n# Exact body\n\nNever changed.  \n");
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/first-note.md');
        rmdir($this->root);
    }

    public function test_accept_updates_front_matter_only_and_preserves_the_recorded_body_hash(): void
    {
        $repository = new ArticleRepository($this->root);
        $original = $repository->read('first-note');
        $store = new InMemoryGeoProposalStore();
        $id = new GeoProposalId('proposal-1');
        $store->save(new GeoProposal($id, 'first-note', hash('sha256', $original->bodyMarkdown), 'metadata', ['summary' => 'A factual summary.', 'topics' => ['PHP']]));

        $accepted = (new ProposalAcceptance($repository, $store))->accept($id);

        self::assertSame($original->bodyMarkdown, $accepted->bodyMarkdown);
        self::assertSame(hash('sha256', $original->bodyMarkdown), hash('sha256', $accepted->bodyMarkdown));
        self::assertSame('A factual summary.', $accepted->frontMatter->get('summary'));
        self::assertSame(['PHP'], $accepted->frontMatter->get('topics'));
    }

    public function test_accept_fails_closed_when_the_saved_body_no_longer_matches_the_reviewed_hash(): void
    {
        $repository = new ArticleRepository($this->root);
        $store = new InMemoryGeoProposalStore();
        $store->save(new GeoProposal(new GeoProposalId('proposal-2'), 'first-note', hash('sha256', "other body\n"), 'metadata', ['summary' => 'Safe proposal']));

        $this->expectException(LogicException::class);
        (new ProposalAcceptance($repository, $store))->accept(new GeoProposalId('proposal-2'));
    }

    public function test_queued_acceptance_uses_full_input_checksum_for_staleness_and_body_hash_for_invariant(): void
    {
        $repository = new ArticleRepository($this->root); $original = $repository->read('first-note'); $store = new InMemoryGeoProposalStore();
        $id = new GeoProposalId('queued-proposal');
        $store->save(new GeoProposal($id, 'first-note', hash('sha256', $original->serialize()), 'summary', 'Queued summary', 'pending', hash('sha256', $original->bodyMarkdown)));
        $accepted = (new ProposalAcceptance($repository, $store))->accept($id);
        self::assertSame('Queued summary', $accepted->frontMatter->get('summary'));
        self::assertSame($original->bodyMarkdown, $accepted->bodyMarkdown);

        $stale = $repository->read('first-note');
        $staleId = new GeoProposalId('stale-metadata');
        $store->save(new GeoProposal($staleId, 'first-note', hash('sha256', $stale->serialize()), 'summary', 'Must reject', 'pending', hash('sha256', $stale->bodyMarkdown)));
        $repository->write($stale->withFrontMatter($stale->frontMatter->with('topics', ['changed'])));
        $this->expectException(LogicException::class);
        (new ProposalAcceptance($repository, $store))->accept($staleId);
    }

    public function test_accept_maps_list_proposals_to_their_front_matter_fields(): void
    {
        $repository = new ArticleRepository($this->root);
        $store = new InMemoryGeoProposalStore();
        $original = $repository->read('first-note');
        $id = new GeoProposalId('entities-list');
        $store->save(new GeoProposal(
            $id,
            'first-note',
            hash('sha256', $original->serialize()),
            'entities',
            ['Ada', 'PHP'],
            'pending',
            hash('sha256', $original->bodyMarkdown),
        ));

        $accepted = (new ProposalAcceptance($repository, $store))->accept($id);

        self::assertSame(['Ada', 'PHP'], $accepted->frontMatter->get('entities'));
        self::assertSame($original->bodyMarkdown, $accepted->bodyMarkdown);
    }

    public function test_accepting_one_proposal_rebases_pending_siblings_from_the_same_review(): void
    {
        $repository = new ArticleRepository($this->root);
        $store = new InMemoryGeoProposalStore();
        $original = $repository->read('first-note');
        $checksum = hash('sha256', $original->serialize());
        $bodyHash = hash('sha256', $original->bodyMarkdown);
        $summaryId = new GeoProposalId('sibling-summary');
        $entitiesId = new GeoProposalId('sibling-entities');
        $store->save(new GeoProposal($summaryId, 'first-note', $checksum, 'summary', 'First change', 'pending', $bodyHash));
        $store->save(new GeoProposal($entitiesId, 'first-note', $checksum, 'entities', ['PHP'], 'pending', $bodyHash));
        $acceptance = new ProposalAcceptance($repository, $store);

        $acceptance->accept($summaryId);
        $accepted = $acceptance->accept($entitiesId);

        self::assertSame('First change', $accepted->frontMatter->get('summary'));
        self::assertSame(['PHP'], $accepted->frontMatter->get('entities'));
        self::assertSame($original->bodyMarkdown, $accepted->bodyMarkdown);
    }

    /**
     * @param array<string, mixed>|list<mixed>|string $proposalValue
     * @param array<string, mixed> $expectedChanges
     */
    #[DataProvider('typedProposalProvider')]
    public function test_accepting_typed_proposals_round_trips_the_expected_front_matter_without_changing_body(
        string $proposalType,
        array|string $proposalValue,
        array $expectedChanges,
    ): void {
        $repository = new ArticleRepository($this->root);
        $original = $repository->read('first-note');
        $store = new InMemoryGeoProposalStore();
        $id = new GeoProposalId('typed-' . str_replace('_', '-', $proposalType));
        $store->save(new GeoProposal(
            $id,
            'first-note',
            hash('sha256', $original->serialize()),
            $proposalType,
            $proposalValue,
            'pending',
            hash('sha256', $original->bodyMarkdown),
        ));

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            (new ProposalAcceptance($repository, $store))->accept($id);
            $reloaded = $repository->read('first-note');
        } finally {
            restore_error_handler();
        }

        foreach ($expectedChanges as $key => $expectedValue) {
            self::assertSame($expectedValue, $reloaded->frontMatter->get($key), 'Unexpected round-trip value for ' . $key);
        }
        self::assertSame('First note', $reloaded->frontMatter->get('title'));
        self::assertSame('first-note', $reloaded->frontMatter->get('slug'));
        self::assertSame('2026-08-12', $reloaded->frontMatter->get('date'));
        self::assertSame($original->bodyMarkdown, $reloaded->bodyMarkdown);
        self::assertSame(hash('sha256', $original->bodyMarkdown), hash('sha256', $reloaded->bodyMarkdown));
    }

    /** @return array<string, array{string, array<string, mixed>|list<mixed>|string, array<string, mixed>}> */
    public static function typedProposalProvider(): array
    {
        return [
            'metadata map' => [
                'metadata',
                ['summary' => 'A reviewed summary.', 'topics' => ['PHP', 'GEO']],
                ['summary' => 'A reviewed summary.', 'topics' => ['PHP', 'GEO']],
            ],
            'entity list' => [
                'entities',
                ['PHP', 'Markdown'],
                ['entities' => ['PHP', 'Markdown']],
            ],
            'source list' => [
                'sources',
                ['https://example.test/evidence', 'https://example.org/reference'],
                ['sources' => ['https://example.test/evidence', 'https://example.org/reference']],
            ],
            'single source URL' => [
                'sources',
                'https://example.test/evidence',
                ['sources' => ['https://example.test/evidence']],
            ],
            'structured data object' => [
                'structured_data',
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => [
                        ['@type' => 'Question', 'name' => 'What is HolyMD?'],
                    ],
                ],
                [
                    'structured_data' => [
                        '@type' => 'FAQPage',
                        'mainEntity' => [
                            ['@type' => 'Question', 'name' => 'What is HolyMD?'],
                        ],
                    ],
                ],
            ],
            'FAQ object list' => [
                'faq_candidates',
                [
                    ['question' => 'What is GEO?', 'answer' => 'Metadata optimization for generative search.'],
                    ['question' => 'Does AI rewrite prose?', 'answer' => 'No.'],
                ],
                [
                    'faq' => [
                        ['question' => 'What is GEO?', 'answer' => 'Metadata optimization for generative search.'],
                        ['question' => 'Does AI rewrite prose?', 'answer' => 'No.'],
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('reservedMetadataKeyProvider')]
    public function test_metadata_proposals_cannot_change_reserved_article_fields(string $key, mixed $value): void
    {
        $repository = new ArticleRepository($this->root);
        $original = $repository->read('first-note');
        $originalBytes = (string) file_get_contents($this->root . '/first-note.md');
        $store = new InMemoryGeoProposalStore();
        $id = new GeoProposalId('reserved-' . str_replace('_', '-', $key));
        $store->save(new GeoProposal(
            $id,
            'first-note',
            hash('sha256', $original->serialize()),
            'metadata',
            [$key => $value],
            'pending',
            hash('sha256', $original->bodyMarkdown),
        ));

        try {
            (new ProposalAcceptance($repository, $store))->accept($id);
            self::fail('Reserved metadata key was accepted: ' . $key);
        } catch (LogicException $exception) {
            self::assertStringContainsString('non-metadata change', $exception->getMessage());
        }

        self::assertSame($originalBytes, file_get_contents($this->root . '/first-note.md'));
        self::assertSame($original->bodyMarkdown, $repository->read('first-note')->bodyMarkdown);
    }

    /** @return array<string, array{string, mixed}> */
    public static function reservedMetadataKeyProvider(): array
    {
        return [
            'title' => ['title', 'AI title'],
            'slug' => ['slug', 'ai-slug'],
            'date' => ['date', '2030-01-01'],
            'status' => ['status', 'published'],
            'previous slugs' => ['previous_slugs', ['old-route']],
            'updated date' => ['updated', '2030-01-01'],
        ];
    }

    #[DataProvider('invalidMetadataShapeProvider')]
    public function test_metadata_proposals_reject_values_that_would_break_publication(string $key, mixed $value): void
    {
        $repository = new ArticleRepository($this->root);
        $original = $repository->read('first-note');
        $originalBytes = (string) file_get_contents($this->root . '/first-note.md');
        $store = new InMemoryGeoProposalStore();
        $id = new GeoProposalId('invalid-' . str_replace('_', '-', $key));
        $store->save(new GeoProposal($id, 'first-note', hash('sha256', $original->serialize()), 'metadata', [$key => $value], 'pending', hash('sha256', $original->bodyMarkdown)));

        $this->expectException(LogicException::class);
        try {
            (new ProposalAcceptance($repository, $store))->accept($id);
        } finally {
            self::assertSame($originalBytes, file_get_contents($this->root . '/first-note.md'));
        }
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidMetadataShapeProvider(): array
    {
        return [
            'summary must be text' => ['summary', ['not' => 'text']],
            'topics must be text list' => ['topics', [['nested' => 'topic']]],
            'sources must be web URLs' => ['sources', ['file:///etc/passwd']],
            'structured data must be object' => ['structured_data', ['list item']],
            'suggestion must be text' => ['metadata_suggestion', ['not' => 'text']],
        ];
    }
}
