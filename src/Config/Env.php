<?php

declare(strict_types=1);

namespace HolyMD\Config;

use RuntimeException;

/**
 * Environment abstraction. Shared hosts disable putenv, so .env values live in
 * an in-memory overlay that always wins over the process environment (which
 * lets tests and bootstrap scripts seed values too).
 */
final class Env
{
    /** @var array<string, string> */
    private static array $overrides = [];

    public static function set(string $name, ?string $value): void
    {
        if ($value === null) {
            unset(self::$overrides[$name]);
        } else {
            self::$overrides[$name] = $value;
        }
    }

    public static function get(string $name): ?string
    {
        if (isset(self::$overrides[$name])) {
            return self::$overrides[$name];
        }
        $value = getenv($name);
        return $value === false ? null : $value;
    }

    /** Loads KEY=VALUE lines from a .env-style file into the overlay. Existing values win. */
    public static function loadFile(string $path): void
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

            if (self::get($name) !== null) {
                continue;
            }

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            self::$overrides[$name] = $value;
        }
    }
}
