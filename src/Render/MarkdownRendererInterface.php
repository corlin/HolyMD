<?php

declare(strict_types=1);

namespace HolyMD\Render;

interface MarkdownRendererInterface
{
    public function render(string $markdown): string;
}
