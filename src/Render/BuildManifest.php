<?php

declare(strict_types=1);

namespace HolyMD\Render;

final readonly class BuildManifest
{
    /** @param list<string> $files */
    public function __construct(public int $articleCount, public array $files)
    {
    }
}
