<?php

declare(strict_types=1);

namespace HolyMD\Database;

use HolyMD\Config\Settings;
use PDO;

final readonly class Connection
{
    public function __construct(private Settings $settings)
    {
    }

    public function pdo(): PDO
    {
        $pdo = new PDO(
            $this->settings->dsn,
            $this->settings->username,
            $this->settings->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('SET @holymd_legacy_offset_seconds = TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return $pdo;
    }
}
