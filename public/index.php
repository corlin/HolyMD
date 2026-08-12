<?php

declare(strict_types=1);

use HolyMD\Bootstrap;
use HolyMD\Admin\ArticleController;
use HolyMD\Admin\VersionService;
use HolyMD\Auth\AdminGuard;
use HolyMD\Content\ArticleRepository;
use HolyMD\Http\Csrf;
use HolyMD\Http\Router;
use HolyMD\Http\ServerRequest;
use HolyMD\Geo\GeoController;
use HolyMD\Geo\GeoProposalStore;
use HolyMD\Geo\FileGeoReviewStore;
use HolyMD\Geo\GeoReviewService;
use HolyMD\Publish\AtomicPublicTree;
use HolyMD\Publish\PublishService;
use HolyMD\Render\StaticBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path) || !str_starts_with($path, '/admin')) {
    http_response_code(404);
    exit;
}

$container = Bootstrap::createContainer();

session_start();
$root = dirname(__DIR__);
$controller = new ArticleController(
    new ArticleRepository($root . '/content/articles'),
    new VersionService($root . '/content/versions'),
    new AdminGuard($_SESSION),
    new Csrf($_SESSION),
    new PublishService(
        new ArticleRepository($root . '/content/articles'), new StaticBuilder(), new AtomicPublicTree(),
        (string) (getenv('HOLYMD_PUBLIC_TREE') ?: $root . '/public/site'), (string) (getenv('HOLYMD_SITE_NAME') ?: 'HolyMD'), (string) (getenv('HOLYMD_SITE_URL') ?: 'https://example.invalid'),
        (string) (getenv('HOLYMD_AUTHOR_NAME') ?: 'Author'), (string) (getenv('HOLYMD_ABOUT') ?: ''),
        getenv('HOLYMD_LLMS_TXT') === '1', $root . '/content/audit',
    ),
);
$geoStore = new FileGeoReviewStore($root . '/content/geo');
$geo = new GeoController(new ArticleRepository($root . '/content/articles'), new GeoReviewService($container->get(\HolyMD\Geo\AiClient::class), $geoStore), $geoStore, new AdminGuard($_SESSION), new Csrf($_SESSION));
$response = Router::admin($controller, $geo)->dispatch(new ServerRequest(
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
