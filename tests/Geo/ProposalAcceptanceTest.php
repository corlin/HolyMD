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
}
