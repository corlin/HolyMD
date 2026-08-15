<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
final class GeoProposal {
    /** @param array<string,mixed>|list<mixed>|string $value */
    public readonly string $bodyHash;
    public function __construct(public readonly string $id, public readonly string $articleSlug, public readonly string $inputChecksum, public readonly string $type, public readonly array|string $value, public readonly string $status = 'pending', ?string $bodyHash = null) {
        $this->bodyHash = $bodyHash ?? $inputChecksum;
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $id) !== 1) throw new InvalidArgumentException('GEO proposal ID is invalid.');
        if (preg_match('/^[a-f0-9]{64}$/', $inputChecksum) !== 1 || preg_match('/^[a-f0-9]{64}$/', $this->bodyHash) !== 1) throw new InvalidArgumentException('GEO proposal checksums are invalid.');
        if (!in_array($type, GeoReview::TYPES, true)) throw new InvalidArgumentException('GEO proposal type is not allowed.');
        if (!in_array($status, ['pending', 'accepted', 'rejected'], true)) throw new InvalidArgumentException('GEO proposal status is invalid.');
    }
}
