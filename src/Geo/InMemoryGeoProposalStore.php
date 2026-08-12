<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
final class InMemoryGeoProposalStore implements GeoProposalStore {
    /** @var array<string,GeoProposal> */ private array $proposals = [];
    public function get(GeoProposalId $id): GeoProposal { return $this->proposals[$id->value] ?? throw new InvalidArgumentException('GEO proposal was not found.'); }
    public function save(GeoProposal $proposal): void { $this->proposals[$proposal->id->value] = $proposal; }
    public function markAccepted(GeoProposalId $id): void { $p = $this->get($id); $this->save(new GeoProposal($p->id, $p->articleSlug, $p->bodyHash, $p->type, $p->value, 'accepted')); }
    public function markRejected(GeoProposalId $id): void { $p = $this->get($id); $this->save(new GeoProposal($p->id, $p->articleSlug, $p->bodyHash, $p->type, $p->value, 'rejected')); }
}
