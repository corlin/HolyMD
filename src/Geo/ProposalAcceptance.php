<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleRepository;
use LogicException;
final readonly class ProposalAcceptance {
    /** @var list<string> */ private const FRONT_MATTER_KEYS = ['summary', 'topics', 'entities', 'faq', 'sources', 'internal_links', 'structured_data', 'hierarchy', 'alt_text'];
    public function __construct(private ArticleRepository $articles, private GeoProposalStore $proposals) {}
    public function accept(GeoProposalId $id): ArticleDocument {
        $proposal = $this->proposals->get($id);
        if ($proposal->status !== 'pending') throw new LogicException('Only pending GEO proposals can be accepted.');
        $original = $this->articles->read($proposal->articleSlug);
        $bodyHash = hash('sha256', $original->bodyMarkdown);
        $legacyBodyOnly = hash_equals($proposal->inputChecksum, $proposal->bodyHash);
        if (!$legacyBodyOnly && !hash_equals($proposal->inputChecksum, hash('sha256', $original->serialize()))) throw new LogicException('The article changed since this GEO proposal was reviewed.');
        if (!hash_equals($proposal->bodyHash, $bodyHash)) throw new LogicException('The article body changed since this GEO proposal was reviewed.');
        $frontMatter = $original->frontMatter;
        foreach ($this->frontMatterChanges($proposal) as $key => $value) $frontMatter = $frontMatter->with($key, $value);
        $accepted = $original->withFrontMatter($frontMatter);
        if (!hash_equals($bodyHash, hash('sha256', $accepted->bodyMarkdown))) throw new LogicException('GEO proposal acceptance must not alter body Markdown.');
        $this->articles->write($accepted);
        $this->proposals->markAccepted($id, hash('sha256', $accepted->serialize()));
        return $accepted;
    }
    public function reject(GeoProposalId $id): void { $this->proposals->markRejected($id); }
    /** @return array<string,mixed> */ private function frontMatterChanges(GeoProposal $proposal): array {
        $changes = match ($proposal->type) {
            'summary' => ['summary' => $proposal->value],
            'entities' => ['entities' => $proposal->value],
            'faq_candidates' => ['faq' => $proposal->value],
            'sources' => is_array($proposal->value)
                ? array_values(array_filter($proposal->value, static fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false))
                : (filter_var((string) $proposal->value, FILTER_VALIDATE_URL) !== false ? [(string) $proposal->value] : []),
            'sources_suggestion' => !is_array($proposal->value) && filter_var((string) $proposal->value, FILTER_VALIDATE_URL) === false ? $proposal->value : null,
            'structured_data' => is_array($proposal->value) && !array_is_list($proposal->value)
                ? $proposal->value
                : (is_string($proposal->value) && is_array(json_decode($proposal->value, true)) ? json_decode($proposal->value, true) : null),
            'structured_data_suggestion' => is_string($proposal->value) && !is_array(json_decode($proposal->value, true)) ? $proposal->value : null,
            'internal_links' => ['internal_links' => $proposal->value],
            'alt_text' => ['alt_text' => $proposal->value],
            'hierarchy' => ['hierarchy' => $proposal->value],
            'metadata' => is_array($proposal->value) && !array_is_list($proposal->value) ? $proposal->value : ['metadata_suggestion' => $proposal->value],
            default => throw new LogicException('This GEO proposal cannot update front matter.'),
        };
        $changes = array_filter($changes, static fn($v) => $v !== null);
        if (!is_array($changes) || array_is_list($changes)) throw new LogicException('This GEO proposal cannot update front matter.');
        foreach ($changes as $key => $_) {
            if (!is_string($key) || in_array(strtolower((string) preg_replace('/[^a-z0-9_]/i', '', $key)), ['body', 'content', 'markdown', 'body_markdown', 'bodymarkdown', 'rewrite'], true)) {
                throw new LogicException('GEO proposal includes a non-metadata change.');
            }
        }
        return $changes;
    }
}
