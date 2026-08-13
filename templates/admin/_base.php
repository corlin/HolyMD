<?php
declare(strict_types=1);
/**
 * Shared admin base-path helper. Subdirectory deployments set
 * HOLYMD_BASE_PATH (e.g. /holymd); root deployments leave it empty.
 *
 * @var string $basePath
 * @var Closure(string): string $path
 */
$basePath = '/' . trim((string) \HolyMD\Config\Env::get('HOLYMD_BASE_PATH'), '/');
if ($basePath === '/') {
    $basePath = '';
}
$path = static fn (string $p): string => $basePath . $p;
