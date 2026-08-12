<?php

declare(strict_types=1);

namespace HolyMD\Deployment;

use Closure;

final readonly class Preflight
{
    private Closure $extensionLoaded;
    private Closure $databaseConnects;
    private Closure $urlFopenEnabled;

    public function __construct(?callable $extensionLoaded = null, ?callable $databaseConnects = null, ?callable $urlFopenEnabled = null)
    {
        $this->extensionLoaded = Closure::fromCallable($extensionLoaded ?? extension_loaded(...));
        $this->databaseConnects = Closure::fromCallable($databaseConnects ?? static fn (): bool => false);
        $this->urlFopenEnabled = Closure::fromCallable($urlFopenEnabled ?? static fn (): bool => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL) === true);
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
        $geoConfigured = ($environment['HOLYMD_GEO_API_CREDENTIAL'] ?? '') !== '' && ($environment['HOLYMD_GEO_API_KEY'] ?? '') !== '';
        if ($geoConfigured && !(($this->urlFopenEnabled)())) {
            $failures[] = 'PHP allow_url_fopen must be enabled for the optional GEO provider.';
        }

        foreach (['content', 'content/articles', 'content/versions', 'content/media', 'content/audit', 'public'] as $relativePath) {
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
        if (in_array('', array_map('trim', $identity), true) || str_contains($identityText, 'replace_with_') || str_contains($identityText, 'example.invalid') || in_array(strtolower(trim($identity[0])), ['holymd', 'site', 'your publication'], true) || in_array(strtolower(trim($identity[2])), ['author', 'your name'], true)) {
            $failures[] = 'Configure truthful public identity values; .env placeholders are not publishable.';
        }
        $siteHost = strtolower((string) parse_url($environment['HOLYMD_SITE_URL'] ?? '', PHP_URL_HOST));
        if (filter_var($environment['HOLYMD_SITE_URL'] ?? '', FILTER_VALIDATE_URL) === false || !str_starts_with($environment['HOLYMD_SITE_URL'] ?? '', 'https://') || $siteHost === 'example.com' || str_ends_with($siteHost, '.example.com')) {
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
