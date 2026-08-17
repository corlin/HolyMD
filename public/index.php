<?php

declare(strict_types=1);

use HolyMD\Bootstrap;
use HolyMD\Config\Env;
use HolyMD\Config\PublicationSettings;
use HolyMD\Admin\ArticleController;
use HolyMD\Admin\JobsController;
use HolyMD\Admin\VersionService;
use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\AuthController;
use HolyMD\Content\ArticleRepository;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use HolyMD\Geo\GeoController;
use HolyMD\Geo\GeoReviewService;
use HolyMD\Geo\MySqlGeoProposalStore;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
use HolyMD\Render\MarkdownRenderer;
use HolyMD\Queue\JobStatusRepository;
use HolyMD\Queue\MySqlJobQueue;

// Flattened deployments (shared hosts with a fixed document root) place
// index.php next to .env; standard deployments keep it in public/.
$root = is_file(__DIR__ . '/.env') ? __DIR__ : dirname(__DIR__);
$flattened = $root === __DIR__;
require $root . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path)) { http_response_code(400); exit; }

// Subdirectory deployments: HOLYMD_BASE_PATH (e.g. /holymd) is stripped from
// the request path before any routing decisions are made.
$basePath = '/' . trim((string) (Env::get('HOLYMD_BASE_PATH') ?: ''), '/');
if ($basePath === '/') {
    $basePath = '';
}
if ($basePath !== '' && $path !== $basePath && !str_starts_with($path, $basePath . '/')) { http_response_code(404); exit; }
if ($basePath !== '') {
    $path = substr($path, strlen($basePath));
    if ($path === '') { $path = '/'; }
}
if (str_starts_with($path, '/media/')) {
    $name = basename(rawurldecode(substr($path, strlen('/media/'))));
    if ($name === '' || $path !== '/media/' . rawurlencode($name) || preg_match('/^[a-z0-9][a-z0-9-]*\.(?:jpg|png|gif|webp)$/', $name) !== 1) { http_response_code(404); exit; }
    $candidate = $root . '/content/media/' . $name;
    if (!is_file($candidate)) { http_response_code(404); exit; }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[strtolower(pathinfo($candidate, PATHINFO_EXTENSION))]);
    header('X-Content-Type-Options: nosniff');
    readfile($candidate);
    exit;
}
if (str_starts_with($path, '/assets/')) {
    $relative = rawurldecode(substr($path, strlen('/assets/')));
    // Only the authored admin assets and the self-hosted icon font live in
    // public/assets (mirroring the .htaccess whitelist, which Apache serves
    // before PHP). Site assets are hashed build outputs and fall through to
    // the static tree below.
    if (in_array($relative, ['admin.css', 'admin.js'], true)) {
        $candidate = $root . ($flattened ? '/assets/' : '/public/assets/') . $relative;
        if (!is_file($candidate)) { http_response_code(404); exit; }
        $types = ['css' => 'text/css', 'js' => 'text/javascript'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($candidate, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        readfile($candidate);
        exit;
    }
    if (preg_match('#^fonts/([a-z0-9][a-z0-9.-]*\.woff2)$#', $relative, $matches) === 1 && $path === '/assets/fonts/' . rawurlencode($matches[1])) {
        $candidate = $root . ($flattened ? '/assets/fonts/' : '/public/assets/fonts/') . $matches[1];
        if (!is_file($candidate)) { http_response_code(404); exit; }
        header('Content-Type: font/woff2');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=604800');
        readfile($candidate);
        exit;
    }
}
if (!str_starts_with($path, '/admin')) {
    // The release pointer is swapped atomically; drop cached path resolutions
    // so php -S development picks up the new tree immediately.
    clearstatcache(true);
    $pointerPath = (string) (Env::get('HOLYMD_PUBLIC_TREE') ?: $root . ($flattened ? '/.holymd-current' : '/public/.holymd-current'));
    $siteRoot = realpath($pointerPath);
    if ($siteRoot === false || !is_dir($siteRoot)) {
        // Pointer file (shared hosts without symlink support): resolve the
        // relative release path it names, confined to the pointer's parent.
        $siteRoot = false;
        if (is_file($pointerPath)) {
            $target = trim((string) file_get_contents($pointerPath));
            if ($target !== '' && preg_match('#^[a-zA-Z0-9._/-]+$#', $target) === 1) {
                $resolved = realpath(dirname($pointerPath) . '/' . $target);
                if ($resolved !== false && is_dir($resolved) && str_starts_with($resolved, dirname($pointerPath) . DIRECTORY_SEPARATOR)) {
                    $siteRoot = $resolved;
                }
            }
        }
    }
    $relative = trim($path, '/');
    $sitePath = $relative === ''
        ? 'index.html'
        : $relative . (str_ends_with($path, '/') ? '/index.html' : '');
    $candidate = $siteRoot === false ? false : realpath($siteRoot . '/' . $sitePath);
    if ($siteRoot === false || $candidate === false || !str_starts_with($candidate, $siteRoot . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        // Historical slug redirects (301) before a truthful 404.
        $redirectsPath = $siteRoot === false ? false : $siteRoot . '/.holymd-redirects.json';
        $redirects = $redirectsPath !== false && is_file($redirectsPath) ? json_decode((string) file_get_contents($redirectsPath), true) : null;
        $redirectTarget = is_array($redirects) && is_string($redirects[rtrim($relative, '/') . '/'] ?? null) ? $redirects[rtrim($relative, '/') . '/'] : null;
        if (is_string($redirectTarget)) {
            \HolyMD\Geo\AiBotDetector::trackIfBot($root, $path, 301);
            header('Location: ' . $basePath . $redirectTarget, true, 301);
            exit;
        }
        $notFound = $siteRoot === false ? false : $siteRoot . '/404.html';
        if ($notFound !== false && is_file($notFound)) {
            \HolyMD\Geo\AiBotDetector::trackIfBot($root, $path, 404);
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            readfile($notFound);
            exit;
        }
        \HolyMD\Geo\AiBotDetector::trackIfBot($root, $path, 404);
        http_response_code(404); exit;
    }
    $types = ['html' => 'text/html; charset=utf-8', 'xml' => 'application/xml', 'json' => 'application/feed+json', 'txt' => 'text/plain; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript'];
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    \HolyMD\Geo\AiBotDetector::trackIfBot($root, $path, 200);
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    readfile($candidate);
    exit;
}

try {
    $container = Bootstrap::createContainer();

    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', '2592000');
        session_set_cookie_params(['httponly' => true, 'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'), 'samesite' => 'Lax', 'path' => '/']);
        if (!@session_start()) {
            throw new RuntimeException('The administrator session could not be started.');
        }
    }
    // Shared hosts without exec/proc_open cannot run the cron worker; with
    // HOLYMD_SYNC_PUBLISH=1 publishes and GEO reviews run in-request instead.
    $syncPublish = Env::get('HOLYMD_SYNC_PUBLISH') === '1';
    $queue = $syncPublish ? null : new MySqlJobQueue($container->get(\PDO::class));
    $pageRepo = new ArticleRepository($root . '/content/pages', ArticleRepository::RESERVED_PAGE_SLUGS);
    $publication = PublicationSettings::fromEnvironment();
    $publisher = new PublishService(
        new ArticleRepository($root . '/content/articles'), new StaticBuilder(), new AtomicPublicTree(),
        (string) (Env::get('HOLYMD_PUBLIC_TREE') ?: $root . ($flattened ? '/.holymd-current' : '/public/.holymd-current')), $publication, $root . '/content/audit',
        null, $root . '/content/holymd-publish.lock', new VersionService($root . '/content/versions'),
        $pageRepo,
        $container->get(\PDO::class),
        new \HolyMD\Geo\GeoScoreCalculator(),
    );
    $controller = new ArticleController(
        new ArticleRepository($root . '/content/articles'),
        new VersionService($root . '/content/versions'),
        new AdminGuard($_SESSION),
        new Csrf($_SESSION),
        $publisher,
        $queue,
        $root . '/content/media',
        [...$publication->adminValues(), 'site_timezone' => \HolyMD\Config\SiteTimezone::fromEnvironment()->identifier()],
        new MarkdownRenderer(),
        new \HolyMD\Geo\GeoScoreCalculator(),
    );
    $geoStore = new MySqlGeoProposalStore($container->get(\PDO::class));
    $geo = new GeoController(new ArticleRepository($root . '/content/articles'), new GeoReviewService($container->get(\HolyMD\Geo\AiClient::class)), $geoStore, new AdminGuard($_SESSION), new Csrf($_SESSION), $queue, new VersionService($root . '/content/versions'));
    $jobs = new JobsController(new JobStatusRepository($container->get(\PDO::class)), new AdminGuard($_SESSION), new Csrf($_SESSION), $container->get(\HolyMD\Admin\AdminTimeFormatter::class));
    $profile = new \HolyMD\Admin\ProfileController($container->get(\PDO::class), new AdminGuard($_SESSION), new Csrf($_SESSION));
    $pages = new \HolyMD\Admin\PageController($pageRepo, new AdminGuard($_SESSION), new Csrf($_SESSION), $publisher, new VersionService($root . '/content/versions'));
    $geoDashboard = new \HolyMD\Admin\GeoDashboardController(new ArticleRepository($root . '/content/articles'), new \HolyMD\Geo\GeoScoreCalculator(), new AdminGuard($_SESSION), new Csrf($_SESSION), $container->get(\PDO::class), $container->get(\HolyMD\Admin\AdminTimeFormatter::class));
    $response = (new Router($controller, $geo, new AuthController($container->get(\PDO::class), $_SESSION, new Csrf($_SESSION)), $jobs, $profile, $pages, $geoDashboard))->dispatch(new ServerRequest(
        (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') ? 'GET' : ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $path,
        array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_UPPER),
        $_POST,
        $_FILES,
    ));

    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $response->body;
} catch (Throwable $exception) {
    error_log('HolyMD administrator request failed: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 60');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Administration unavailable · HolyMD</title><link rel="stylesheet" href="/assets/admin.css"></head><body><main class="result-page"><h1>Administration is temporarily unavailable</h1><p role="alert">HolyMD could not connect to its administrator runtime. Check the database and environment configuration, then retry.</p></main></body></html>';
}
