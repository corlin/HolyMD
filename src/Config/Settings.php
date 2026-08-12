<?php

declare(strict_types=1);

namespace HolyMD\Config;

use RuntimeException;

final readonly class Settings
{
    public function __construct(
        public string $dsn,
        public ?string $username,
        public ?string $password,
    ) {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        self::loadEnvironmentFile($projectRoot . '/.env');

        $dsn = self::value('HOLYMD_DSN');

        if ($dsn === null || $dsn === '') {
            throw new RuntimeException('HOLYMD_DSN must be configured.');
        }

        return new self(
            $dsn,
            self::value('HOLYMD_DB_USERNAME'),
            self::value('HOLYMD_DB_PASSWORD'),
        );
    }

    private static function loadEnvironmentFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('Unable to read environment file "%s".', $path));
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                throw new RuntimeException('Invalid environment assignment in .env.');
            }

            $name = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                throw new RuntimeException('Invalid environment variable name in .env.');
            }

            if (self::value($name) !== null) {
                continue;
            }

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            putenv(sprintf('%s=%s', $name, $value));
        }
    }

    private static function value(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
