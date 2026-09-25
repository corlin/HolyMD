<?php

declare(strict_types=1);

namespace HolyMD\Geo;

final readonly class CitationProbeAnswer
{
    /** @param list<string> $citations */
    public function __construct(public string $text, public array $citations)
    {
    }
}
