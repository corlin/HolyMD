<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use InvalidArgumentException;

final readonly class ArticleId
{
    public function __construct(public string $slug)
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new InvalidArgumentException('Article ID must be a lowercase URL-safe slug.');
        }
    }
}
