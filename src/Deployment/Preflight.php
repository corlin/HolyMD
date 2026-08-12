<?php

declare(strict_types=1);

namespace HolyMD\Deployment;

use Closure;

final readonly class Preflight
{
    private Closure $extensionLoaded;
    private Closure $databaseConnects;

    public function __construct(?callable $extensionLoaded = null, ?callable $databaseConnects = null)
    {
        $this->extensionLoaded = Closure::fromCallable($extensionLoaded ?? extension_loaded(...));
        $this->databaseConnects = Closure::fromCallable($databaseConnects ?? static fn (): bool => false);
    }

    /** @param array<string, string> $environment */
    public function check(string $projectRoot, array $environment): PreflightReport
    {
        $failures = [];
        foreach (['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'exif', 'sodium', 'openssl', 'json'] as $extension) {
            if (!(($this->extensionLoaded)($extension))) {
                $failures[] = "Required PHP extension {$extension} is not loaded.";
            }
        }
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL) !== true) {
            $failures[] = 'PHP allow_url_fopen must be enabled for the optional GEO provider.';
        }

        foreach (['content/articles', 'content/media', 'public'] as $relativePath) {
            $path = $projectRoot . '/' . $relativePath;
            if (!is_dir($path) || !is_writable($path)) {
                $failures[] = "Directory {$relativePath} must exist and be writable by PHP.";
            }
        }

        $identity = [
            $environment['HOLYMD_SITE_NAME'] ?? '',
            $environment['HOLYMD_SITE_URL'] ?? '',
            $environment['HOLYMD_AUTHOR_NAME'] ?? '',
            $environment['HOLYMD_ABOUT'] ?? '',
        ];
        $identityText = strtolower(implode(' ', $identity));
        if (in_array('', array_map('trim', $identity), true) || str_contains($identityText, 'replace_with_') || str_contains($identityText, 'example.invalid')) {
            $failures[] = 'Configure truthful public identity values; .env placeholders are not publishable.';
        }
        if (filter_var($environment['HOLYMD_SITE_URL'] ?? '', FILTER_VALIDATE_URL) === false || !str_starts_with($environment['HOLYMD_SITE_URL'] ?? '', 'https://')) {
            $failures[] = 'HOLYMD_SITE_URL must be an absolute HTTPS URL.';
        }
        if (!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $environment['HOLYMD_SITE_LANGUAGE'] ?? '')) {
            $failures[] = 'HOLYMD_SITE_LANGUAGE must be a valid language tag such as zh-CN.';
        }
        if (!str_starts_with($environment['HOLYMD_DSN'] ?? '', 'mysql:')) {
            $failures[] = 'HOLYMD_DSN must select a MySQL database.';
        }

        try {
            if (!(($this->databaseConnects)())) {
                $failures[] = 'MySQL connection or schema check failed.';
            }
        } catch (\Throwable) {
            $failures[] = 'MySQL connection or schema check failed.';
        }

        $pointer = (string) ($environment['HOLYMD_PUBLIC_TREE'] ?? ($projectRoot . '/public/.holymd-current'));
        if (!is_link($pointer) || !is_dir($pointer)) {
            $failures[] = 'The static release pointer is not prepared; run bin/holymd-prepare-release.php.';
        }

        return new PreflightReport(array_values(array_unique($failures)));
    }
}
