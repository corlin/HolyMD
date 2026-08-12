<?php

declare(strict_types=1);

use HolyMD\Bootstrap;
use HolyMD\Admin\ArticleController;
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
use HolyMD\Queue\MySqlJobQueue;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path)) { http_response_code(400); exit; }
$root = dirname(__DIR__);
if (!str_starts_with($path, '/admin')) {
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

$container = Bootstrap::createContainer();

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'), 'samesite' => 'Lax', 'path' => '/']);
    session_start();
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
);
$geoStore = new MySqlGeoProposalStore($container->get(\PDO::class));
$geo = new GeoController(new ArticleRepository($root . '/content/articles'), new GeoReviewService($container->get(\HolyMD\Geo\AiClient::class)), $geoStore, new AdminGuard($_SESSION), new Csrf($_SESSION), new MySqlJobQueue($container->get(\PDO::class)), new VersionService($root . '/content/versions'));
$response = (new Router($controller, $geo, new AuthController($container->get(\PDO::class), $_SESSION, new Csrf($_SESSION))))->dispatch(new ServerRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    array_change_key_case(getallheaders() ?: [], CASE_UPPER),
    $_POST,
));

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
