<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use HolyMD\Render\BuildManifest;

final readonly class PublishResult
{
    public function __construct(public BuildManifest $manifest, public ValidationReport $validation)
    {
    }
}
