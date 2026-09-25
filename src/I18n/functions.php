<?php

declare(strict_types=1);

use HolyMD\I18n\Translator;

if (!function_exists('__')) {
    /**
     * Translate an administrator interface string and escape it for HTML.
     *
     * @param array<string, string|int> $params
     */
    function __(string $source, array $params = []): string
    {
        return htmlspecialchars(Translator::text($source, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
