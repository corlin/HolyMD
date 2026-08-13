#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Admin\AccountCommands;
use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use HolyMD\Geo\EncryptedApiCredential;
use HolyMD\Queue\JobStatusRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage:\n"
    . "  holymd-admin.php create --email <email> --display-name <name>\n"
    . "  holymd-admin.php list\n"
    . "  holymd-admin.php password-reset --email <email>\n"
    . "  holymd-admin.php disable --email <email>\n"
    . "  holymd-admin.php enable --email <email>\n"
    . "  holymd-admin.php unlock --email <email>\n"
    . "  holymd-admin.php jobs\n"
    . "  HOLYMD_GEO_PLAINTEXT_KEY='<provider-key>' holymd-admin.php encrypt-geo-key\n\n"
    . "Administrator password: set HOLYMD_ADMIN_PASSWORD in the environment (minimum 12 characters).\n";
$command = $argv[1] ?? null;

if ($command === 'encrypt-geo-key') {
    $plain = getenv('HOLYMD_GEO_PLAINTEXT_KEY');
    if (!is_string($plain) || $plain === '') {
        fwrite(STDERR, "Set HOLYMD_GEO_PLAINTEXT_KEY for this command only.\n");
        exit(64);
    }
    $encrypted = EncryptedApiCredential::encrypt($plain);
    fwrite(STDOUT, 'HOLYMD_GEO_API_CREDENTIAL="' . $encrypted['credential'] . '"' . "\n");
    fwrite(STDOUT, 'HOLYMD_GEO_API_KEY="' . $encrypted['key'] . '"' . "\n");
    exit(0);
}

if (!in_array($command, ['create', 'list', 'password-reset', 'disable', 'enable', 'unlock', 'jobs'], true)) {
    fwrite(STDERR, $usage);
    exit(64);
}

$option = static function (string $name) use ($argv): ?string {
    $index = array_search($name, $argv, true);
    $value = $index === false ? null : ($argv[$index + 1] ?? null);
    return is_string($value) && $value !== '' ? $value : null;
};

$email = $option('--email');
if (in_array($command, ['password-reset', 'disable', 'enable', 'unlock'], true) && (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    fwrite(STDERR, "Provide a valid --email.\n");
    exit(64);
}

$password = getenv('HOLYMD_ADMIN_PASSWORD');
if (in_array($command, ['create', 'password-reset'], true) && (!is_string($password) || strlen($password) < 12)) {
    fwrite(STDERR, "Set HOLYMD_ADMIN_PASSWORD in the environment (minimum 12 characters).\n");
    exit(64);
}

try {
    $pdo = (new Connection(Settings::fromEnvironment(dirname(__DIR__))))->pdo();
} catch (Throwable $error) {
    fwrite(STDERR, 'Database unavailable: ' . $error->getMessage() . "\n");
    exit(1);
}
$accounts = new AccountCommands($pdo);

try {
    switch ($command) {
        case 'create':
            $accounts->create((string) $email, (string) $option('--display-name'), (string) $password);
            fwrite(STDOUT, "Administrator created.\n");
            break;
        case 'list':
            foreach ($accounts->list() as $account) {
                fwrite(STDOUT, sprintf(
                    "%d\t%s\t%s\t%s\t%d failure(s)\t%s\n",
                    (int) $account['id'],
                    (string) $account['email'],
                    (string) $account['display_name'],
                    (int) $account['is_active'] === 1 ? 'active' : 'disabled',
                    (int) $account['failed_attempts'],
                    $account['locked_until'] === null ? '' : 'locked until ' . $account['locked_until'] . ' UTC',
                ));
            }
            break;
        case 'password-reset':
            $accounts->passwordReset((string) $email, (string) $password);
            fwrite(STDOUT, "Password updated and lock cleared.\n");
            break;
        case 'disable':
            $accounts->disable((string) $email);
            fwrite(STDOUT, "Administrator disabled.\n");
            break;
        case 'enable':
            $accounts->enable((string) $email);
            fwrite(STDOUT, "Administrator enabled.\n");
            break;
        case 'unlock':
            $accounts->unlock((string) $email);
            fwrite(STDOUT, "Administrator unlocked.\n");
            break;
        case 'jobs':
            $repository = new JobStatusRepository($pdo);
            foreach ($repository->summary() as $row) {
                fwrite(STDOUT, sprintf("%s\t%s\t%d\n", $row['job_type'], $row['status'], $row['count']));
            }
            fwrite(STDOUT, "-- recent --\n");
            foreach ($repository->recent() as $job) {
                $error = $job['last_error'] === null ? '' : ' error: ' . substr(str_replace(["\r", "\n"], ' ', $job['last_error']), 0, 200);
                fwrite(STDOUT, sprintf("#%d\t%s\t%s\t%s\t%s\tattempts=%d%s\n", (int) $job['id'], $job['job_type'], $job['status'], $job['slug'] ?? '-', $job['action'] ?? '-', (int) $job['attempts'], $error));
            }
            break;
    }
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(64);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
exit(0);
