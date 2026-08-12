<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
final readonly class GeoProposalId { public function __construct(public string $value) { if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $value) !== 1) throw new InvalidArgumentException('GEO proposal ID is invalid.'); } }
