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

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path) || !str_starts_with($path, '/admin')) {
    http_response_code(404);
    exit;
}

Bootstrap::createContainer();

session_start();
$root = dirname(__DIR__);
$controller = new ArticleController(
    new ArticleRepository($root . '/content/articles'),
    new VersionService($root . '/content/versions'),
    new AdminGuard($_SESSION),
    new Csrf($_SESSION),
);
$response = Router::admin($controller)->dispatch(new ServerRequest(
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
