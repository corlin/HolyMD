<?php

declare(strict_types=1);

namespace HolyMD\Admin;

use DateTimeImmutable;
use DateTimeZone;
use HolyMD\Config\SiteTimezone;
use InvalidArgumentException;

final readonly class AdminTimeFormatter
{
    public function __construct(private SiteTimezone $timezone)
    {
    }

    public function format(?string $utc, string $format = 'Y-m-d H:i'): string
    {
        if ($utc === null || trim($utc) === '') {
            return '';
        }

        $source = new DateTimeZone('UTC');
        $date = str_contains($utc, '.')
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $utc, $source)
            : DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, $source);
        if ($date === false) {
            throw new InvalidArgumentException('Stored administrator timestamp must be UTC datetime text.');
        }

        return $date->setTimezone($this->timezone->zone())->format($format);
    }
}
