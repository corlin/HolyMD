<?php

declare(strict_types=1);

namespace HolyMD\Tests\Admin;

use HolyMD\Admin\AdminTimeFormatter;
use HolyMD\Config\SiteTimezone;
use PHPUnit\Framework\TestCase;

final class AdminTimeFormatterTest extends TestCase
{
    public function test_formats_stored_utc_in_the_site_timezone(): void
    {
        $formatter = new AdminTimeFormatter(SiteTimezone::fromValue('Asia/Singapore'));

        self::assertSame('2026-08-17 20:00', $formatter->format('2026-08-17 12:00:00'));
    }

    public function test_preserves_microseconds_and_supports_custom_formats(): void
    {
        $formatter = new AdminTimeFormatter(SiteTimezone::fromValue('America/New_York'));

        self::assertSame('2026-08-17 08:00:00.123456 EDT', $formatter->format('2026-08-17 12:00:00.123456', 'Y-m-d H:i:s.u T'));
    }

    public function test_returns_an_empty_string_for_missing_values(): void
    {
        $formatter = new AdminTimeFormatter(SiteTimezone::fromValue('UTC'));

        self::assertSame('', $formatter->format(null));
        self::assertSame('', $formatter->format(''));
    }
}
