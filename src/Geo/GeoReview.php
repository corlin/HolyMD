<?php
declare(strict_types=1);
namespace HolyMD\Geo;
final readonly class GeoReview {
    /** @var list<string> */ public const TYPES = ['summary', 'metadata', 'entities', 'faq_candidates', 'sources', 'hierarchy', 'alt_text', 'internal_links', 'structured_data'];
    /** @param list<GeoProposal> $proposals @param list<string> $findings */
    public function __construct(public string $articleSlug, public string $bodyHash, public array $proposals, public array $findings, public string $status = 'completed') {}
}
