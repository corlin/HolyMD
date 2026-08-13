<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\ArticleMetadataValidator;
use HolyMD\Content\ArticleRepository;
use LogicException;
final readonly class ProposalAcceptance {
    /** @var list<string> */
    private const FRONT_MATTER_KEYS = [
        'summary',
        'topics',
        'entities',
        'faq',
        'sources',
        'sources_suggestion',
        'internal_links',
        'structured_data',
        'structured_data_suggestion',
        'hierarchy',
        'alt_text',
        'metadata_suggestion',
    ];
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
            'sources' => $this->sourceChanges($proposal->value),
            'internal_links' => ['internal_links' => $proposal->value],
            'alt_text' => ['alt_text' => $proposal->value],
            'hierarchy' => ['hierarchy' => $proposal->value],
            'structured_data' => $this->structuredDataChanges($proposal->value),
            'metadata' => is_array($proposal->value) && !array_is_list($proposal->value) ? $proposal->value : ['metadata_suggestion' => $proposal->value],
            default => throw new LogicException('This GEO proposal cannot update front matter.'),
        };
        if (!is_array($changes) || array_is_list($changes)) throw new LogicException('This GEO proposal cannot update front matter.');
        foreach ($changes as $key => $value) {
            if (!is_string($key) || !in_array($key, self::FRONT_MATTER_KEYS, true)) {
                throw new LogicException('GEO proposal includes a non-metadata change.');
            }
            if (!$this->validFrontMatterValue($key, $value)) throw new LogicException('GEO proposal contains metadata that cannot be published safely.');
        }
        return $changes;
    }

    /** @param array<mixed>|string $value @return array<string, mixed> */
    private function sourceChanges(array|string $value): array
    {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false
                ? ['sources' => [$value]]
                : ['sources_suggestion' => $value];
        }

        return ['sources' => array_values($value)];
    }

    /** @param array<mixed>|string $value @return array<string, mixed> */
    private function structuredDataChanges(array|string $value): array
    {
        if (is_array($value) && !array_is_list($value)) {
            return ['structured_data' => $value];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && !array_is_list($decoded)) {
                return ['structured_data' => $decoded];
            }
            return ['structured_data_suggestion' => $value];
        }

        return ['structured_data_suggestion' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    private function validFrontMatterValue(string $key, mixed $value): bool
    {
        if (in_array($key, ['summary', 'sources_suggestion', 'structured_data_suggestion', 'metadata_suggestion'], true)) {
            return is_string($value) && trim($value) !== '';
        }
        if ($key === 'topics') return $this->stringList($value);
        if ($key === 'sources') {
            return $this->stringList($value) && array_reduce(
                $value,
                fn (bool $valid, string $source): bool => $valid && $this->webUrl($source),
                true,
            );
        }
        if ($key === 'structured_data') return is_array($value) && !array_is_list($value) && $value !== [] && !ArticleMetadataValidator::containsForbiddenKey($value);
        if (in_array($key, ['entities', 'faq', 'internal_links', 'hierarchy', 'alt_text'], true)) {
            return (is_string($value) && trim($value) !== '') || (is_array($value) && !ArticleMetadataValidator::containsForbiddenKey($value));
        }
        return false;
    }

    private function stringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_reduce(
            $value,
            static fn (bool $valid, mixed $item): bool => $valid && is_string($item) && trim($item) !== '',
            true,
        );
    }

    private function webUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) return false;
        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
