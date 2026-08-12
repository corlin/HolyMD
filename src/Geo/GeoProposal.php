<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
final readonly class GeoProposal {
    /** @param array<string,mixed>|list<mixed>|string $value */
    public function __construct(public GeoProposalId $id, public string $articleSlug, public string $bodyHash, public string $type, public array|string $value, public string $status = 'pending') {
        if (preg_match('/^[a-f0-9]{64}$/', $bodyHash) !== 1) throw new InvalidArgumentException('GEO proposal body hash is invalid.');
        if (!in_array($type, GeoReview::TYPES, true)) throw new InvalidArgumentException('GEO proposal type is not allowed.');
        if (!in_array($status, ['pending', 'accepted', 'rejected'], true)) throw new InvalidArgumentException('GEO proposal status is invalid.');
    }
}
