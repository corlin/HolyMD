<?php

declare(strict_types=1);

namespace HolyMD\Render;

use RuntimeException;

final readonly class TemplateRenderer
{
    public function __construct(private string $templateRoot)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data): string
    {
        $path = $this->templateRoot . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Template "%s" was not found.', $template));
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
