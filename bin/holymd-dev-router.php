<?php

declare(strict_types=1);

$publicRoot = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path)) {
    http_response_code(400);
    return true;
}

// Let PHP's development server deliver real runtime assets directly. Public
// article routes are intentionally handled by the HolyMD front controller so
// clean URLs map to the generated public/site tree during local development.
$runtimeAsset = realpath($publicRoot . $path);
if (
    $runtimeAsset !== false
    && str_starts_with($runtimeAsset, $publicRoot . DIRECTORY_SEPARATOR)
    && !str_starts_with($runtimeAsset, $publicRoot . DIRECTORY_SEPARATOR . 'site' . DIRECTORY_SEPARATOR)
    && is_file($runtimeAsset)
) {
    return false;
}

require $publicRoot . '/index.php';

return true;
