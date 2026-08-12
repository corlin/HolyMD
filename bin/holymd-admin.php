#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Config\Settings;
use HolyMD\Database\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: holymd-admin.php create --email <email> --display-name <name>\nPassword: set HOLYMD_ADMIN_PASSWORD in the environment (minimum 12 characters).\n";
if (($argv[1] ?? null) !== 'create') { fwrite(STDERR, $usage); exit(64); }
$option = static function (string $name) use ($argv): ?string {
    $index = array_search($name, $argv, true);
    $value = $index === false ? null : ($argv[$index + 1] ?? null);
    return is_string($value) && $value !== '' ? $value : null;
};
$email = $option('--email');
$displayName = $option('--display-name');
$password = getenv('HOLYMD_ADMIN_PASSWORD');
if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !is_string($displayName) || trim($displayName) === '' || !is_string($password) || strlen($password) < 12) { fwrite(STDERR, $usage); exit(64); }
try {
    $pdo = (new Connection(Settings::fromEnvironment(dirname(__DIR__)))->pdo());
    $pdo->prepare('INSERT INTO admin_users (email, password_hash, display_name) VALUES (?, ?, ?)')->execute([mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), trim($displayName)]);
    fwrite(STDOUT, "Administrator created.\n");
} catch (\PDOException) {
    fwrite(STDERR, "Administrator was not created (the email may already exist).\n");
    exit(1);
}
