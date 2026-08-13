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

    /**
     * Parses a PDO MySQL DSN into the connection parts mysqldump needs.
     *
     * @return array{host: ?string, port: ?string, socket: ?string, dbname: string}
     */
    public static function mysqlParts(string $dsn): array
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('HOLYMD_DSN must be a MySQL DSN.');
        }
        $parts = ['host' => null, 'port' => null, 'socket' => null, 'dbname' => null];
        foreach (explode(';', substr($dsn, 6)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $separator = strpos($pair, '=');
            if ($separator === false) {
                throw new RuntimeException('Invalid MySQL DSN.');
            }
            $key = substr($pair, 0, $separator);
            $value = substr($pair, $separator + 1);
            if ($key === 'unix_socket') {
                $key = 'socket';
            }
            if (array_key_exists($key, $parts)) {
                $parts[$key] = $value;
            }
        }
        if (!is_string($parts['dbname']) || $parts['dbname'] === '') {
            throw new RuntimeException('HOLYMD_DSN must include a database name.');
        }
        /** @var array{host: ?string, port: ?string, socket: ?string, dbname: string} $parts */
        return $parts;
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        Env::loadFile($projectRoot . '/.env');

        $dsn = Env::get('HOLYMD_DSN');

        if ($dsn === null || $dsn === '') {
            throw new RuntimeException('HOLYMD_DSN must be configured.');
        }

        return new self(
            $dsn,
            Env::get('HOLYMD_DB_USERNAME'),
            Env::get('HOLYMD_DB_PASSWORD'),
        );
    }
}
