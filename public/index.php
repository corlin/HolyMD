<?php

declare(strict_types=1);

use HolyMD\Bootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($path) || !str_starts_with($path, '/admin')) {
    http_response_code(404);
    exit;
}

Bootstrap::createContainer();

http_response_code(501);
header('Content-Type: text/plain; charset=utf-8');
echo 'The HolyMD administration interface has not been installed yet.';
