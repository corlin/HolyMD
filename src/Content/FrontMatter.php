<?php

declare(strict_types=1);

namespace HolyMD\Content;

use InvalidArgumentException;

final readonly class FrontMatter
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    /** @return array{0: self, 1: string} */
    public static function parse(string $markdown): array
    {
        if (!str_starts_with($markdown, "---\n") && !str_starts_with($markdown, "---\r\n")) {
            throw new InvalidArgumentException('Article must start with YAML front matter.');
        }

        // Front matter owns its delimiter style. The Markdown body may contain
        // different line endings after a browser/editor round trip.
        $lineEnding = str_starts_with($markdown, "---\r\n") ? "\r\n" : "\n";
        $marker = $lineEnding . '---' . $lineEnding;
        $end = strpos($markdown, $marker, 3);
        if ($end === false) {
            throw new InvalidArgumentException('Article front matter is not terminated.');
        }

        $yaml = substr($markdown, 3 + strlen($lineEnding), $end - (3 + strlen($lineEnding)));
        $body = substr($markdown, $end + strlen($marker));
        $values = [];
        $activeList = null;
        foreach (explode($lineEnding, $yaml) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^  - (.+)$/', $line, $matches) === 1 && $activeList !== null) {
                $values[$activeList][] = self::scalar($matches[1]);
                continue;
            }
            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):(?: (.*))?$/', $line, $matches) !== 1) {
                throw new InvalidArgumentException('Unsupported YAML front matter syntax.');
            }
            $key = $matches[1];
            $value = $matches[2] ?? '';
            if ($value === '') {
                $values[$key] = [];
                $activeList = $key;
            } else {
                $values[$key] = self::scalar($value);
                $activeList = null;
            }
        }

        return [new self($values), $body];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $values = $this->values;
        $values[$key] = $value;
        return new self($values);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function toYaml(): string
    {
        $lines = [];
        foreach ($this->values as $key => $value) {
            if (is_array($value)) {
                $lines[] = $key . ':';
                foreach ($value as $item) {
                    $lines[] = '  - ' . self::encodeScalar($item);
                }
                continue;
            }
            $lines[] = $key . ': ' . self::encodeScalar($value);
        }
        return implode("\n", $lines);
    }

    private static function scalar(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        return $value;
    }

    private static function encodeScalar(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/[:#\[\]{}",]|[\\\r\n]|^\s|\s$/', $value) === 1 ? '"' . addcslashes($value, "\\\"\n\r") . '"' : $value;
    }
}
