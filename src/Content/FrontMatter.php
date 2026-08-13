<?php

declare(strict_types=1);

namespace HolyMD\Content;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

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
        try {
            $values = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('Unsupported YAML front matter syntax.', previous: $exception);
        }
        if (!is_array($values)) {
            if ($values === null) {
                $values = [];
            } else {
                throw new InvalidArgumentException('Unsupported YAML front matter syntax.');
            }
        }
        return [new self(self::resolve($values)), $body];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function resolve(array $values): array
    {
        $resolved = [];
        foreach ($values as $key => $value) {
            $value = self::resolveValue($value);
            // Legacy files leave `date: 2026-08-12` unquoted; symfony/yaml parses
            // YAML 1.1 timestamps as ints. Restore the Y-m-d string so content
            // semantics do not change (the dumper quotes these strings on save).
            if (($key === 'date' || $key === 'updated') && is_int($value)) {
                $value = gmdate('Y-m-d', $value);
            }
            $resolved[$key] = $value;
        }
        return $resolved;
    }

    private static function resolveValue(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            if ($value->getTag() !== 'holymd-json') {
                throw new InvalidArgumentException('Unsupported YAML front matter tag.');
            }
            $decoded = $value->getValue();
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('JSON front matter values must decode to an array.');
            }
            return self::resolve($decoded);
        }
        if (is_array($value)) {
            return self::resolve($value);
        }
        return $value;
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

    public function without(string $key): self
    {
        $values = $this->values;
        unset($values[$key]);
        return new self($values);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function toYaml(): string
    {
        return rtrim(Yaml::dump($this->values, 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE), "\n");
    }
}
