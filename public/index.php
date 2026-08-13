<?php

declare(strict_types=1);

use HolyMD\Bootstrap;
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
use HolyMD\Geo\GeoProposalStore;
use HolyMD\Geo\FileGeoReviewStore;
use HolyMD\Geo\GeoReviewService;
use HolyMD\Geo\MySqlGeoProposalStore;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;
use HolyMD\Render\MarkdownRenderer;
use HolyMD\Queue\JobStatusRepository;
use HolyMD\Queue\MySqlJobQueue;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path)) { http_response_code(400); exit; }
$root = dirname(__DIR__);
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
        $candidate = $root . '/public/assets/' . $relative;
        if (!is_file($candidate)) { http_response_code(404); exit; }
        $types = ['css' => 'text/css', 'js' => 'text/javascript'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($candidate, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        readfile($candidate);
        exit;
    }
    if (preg_match('#^fonts/([a-z0-9][a-z0-9.-]*\.woff2)$#', $relative, $matches) === 1 && $path === '/assets/fonts/' . rawurlencode($matches[1])) {
        $candidate = $root . '/public/assets/fonts/' . $matches[1];
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
    // so php -S development picks up the new tree immediately. Apache serves
    // these files before PHP in production, so this only affects development.
    clearstatcache(true);
    $siteRoot = realpath((string) (getenv('HOLYMD_PUBLIC_TREE') ?: $root . '/public/.holymd-current'));
    $relative = trim($path, '/');
    $sitePath = $relative === ''
        ? 'index.html'
        : $relative . (str_ends_with($path, '/') ? '/index.html' : '');
    $candidate = $siteRoot === false ? false : realpath($siteRoot . '/' . $sitePath);
    if ($siteRoot === false || $candidate === false || !str_starts_with($candidate, $siteRoot . DIRECTORY_SEPARATOR) || !is_file($candidate)) { http_response_code(404); exit; }
    $types = ['html' => 'text/html; charset=utf-8', 'xml' => 'application/xml', 'json' => 'application/feed+json', 'txt' => 'text/plain; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript'];
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    readfile($candidate);
    exit;
}

try {
    $container = Bootstrap::createContainer();

    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'), 'samesite' => 'Lax', 'path' => '/']);
        if (!@session_start()) {
            throw new RuntimeException('The administrator session could not be started.');
        }
    }
    $controller = new ArticleController(
    new ArticleRepository($root . '/content/articles'),
    new VersionService($root . '/content/versions'),
    new AdminGuard($_SESSION),
    new Csrf($_SESSION),
    new PublishService(
        new ArticleRepository($root . '/content/articles'), new StaticBuilder(), new AtomicPublicTree(),
        (string) (getenv('HOLYMD_PUBLIC_TREE') ?: $root . '/public/.holymd-current'), (string) (getenv('HOLYMD_SITE_NAME') ?: 'HolyMD'), (string) (getenv('HOLYMD_SITE_URL') ?: 'https://example.invalid'),
        (string) (getenv('HOLYMD_AUTHOR_NAME') ?: 'Author'), (string) (getenv('HOLYMD_ABOUT') ?: ''),
        getenv('HOLYMD_LLMS_TXT') === '1', $root . '/content/audit',
        null, $root . '/content/holymd-publish.lock', (string) (getenv('HOLYMD_SITE_LANGUAGE') ?: 'zh-CN'),
    ),
    new MySqlJobQueue($container->get(\PDO::class)),
    $root . '/content/media',
    ['site_name' => (string) (getenv('HOLYMD_SITE_NAME') ?: 'HolyMD'), 'site_url' => (string) (getenv('HOLYMD_SITE_URL') ?: 'https://example.invalid'), 'author_name' => (string) (getenv('HOLYMD_AUTHOR_NAME') ?: 'Author'), 'about' => (string) (getenv('HOLYMD_ABOUT') ?: ''), 'site_language' => (string) (getenv('HOLYMD_SITE_LANGUAGE') ?: 'zh-CN')],
    new MarkdownRenderer(),
    );
    $geoStore = new MySqlGeoProposalStore($container->get(\PDO::class));
    $geo = new GeoController(new ArticleRepository($root . '/content/articles'), new GeoReviewService($container->get(\HolyMD\Geo\AiClient::class)), $geoStore, new AdminGuard($_SESSION), new Csrf($_SESSION), new MySqlJobQueue($container->get(\PDO::class)), new VersionService($root . '/content/versions'));
    $jobs = new JobsController(new JobStatusRepository($container->get(\PDO::class)), new AdminGuard($_SESSION), new Csrf($_SESSION));
    $response = (new Router($controller, $geo, new AuthController($container->get(\PDO::class), $_SESSION, new Csrf($_SESSION)), $jobs))->dispatch(new ServerRequest(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
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
