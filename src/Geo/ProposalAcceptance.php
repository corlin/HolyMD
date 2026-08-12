<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use LogicException;
final readonly class ProposalAcceptance {
    /** @var list<string> */ private const FRONT_MATTER_KEYS = ['summary', 'topics', 'entities', 'faq', 'sources', 'internal_links', 'structured_data'];
    public function __construct(private ArticleRepository $articles, private GeoProposalStore $proposals) {}
    public function accept(GeoProposalId $id): ArticleDocument {
        $proposal = $this->proposals->get($id);
        if ($proposal->status !== 'pending') throw new LogicException('Only pending GEO proposals can be accepted.');
        $original = $this->articles->read($proposal->articleSlug);
        if (!hash_equals($proposal->bodyHash, hash('sha256', $original->bodyMarkdown)) && !hash_equals($proposal->bodyHash, hash('sha256', $original->serialize()))) throw new LogicException('The article changed since this GEO proposal was reviewed.');
        $frontMatter = $original->frontMatter;
        foreach ($this->frontMatterChanges($proposal) as $key => $value) $frontMatter = $frontMatter->with($key, $value);
        $accepted = $original->withFrontMatter($frontMatter);
        if (!hash_equals($proposal->bodyHash, hash('sha256', $accepted->bodyMarkdown))) throw new LogicException('GEO proposal acceptance must not alter body Markdown.');
        $this->articles->write($accepted); $this->proposals->markAccepted($id); return $accepted;
    }
    public function reject(GeoProposalId $id): void { $this->proposals->markRejected($id); }
    /** @return array<string,mixed> */ private function frontMatterChanges(GeoProposal $proposal): array {
        $changes = $proposal->type === 'summary' ? ['summary' => $proposal->value] : $proposal->value;
        if (!is_array($changes) || array_is_list($changes)) throw new LogicException('This GEO proposal cannot update front matter.');
        foreach ($changes as $key => $_) if (!is_string($key) || !in_array($key, self::FRONT_MATTER_KEYS, true)) throw new LogicException('GEO proposal includes a non-metadata change.');
        return $changes;
    }
}
