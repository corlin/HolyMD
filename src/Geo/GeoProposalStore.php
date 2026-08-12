<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
interface GeoProposalStore extends GeoReviewStore { public function get(GeoProposalId $id): GeoProposal; public function save(GeoProposal $proposal): void; public function markAccepted(GeoProposalId $id): void; public function markRejected(GeoProposalId $id): void; }
