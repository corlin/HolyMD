<?php

declare(strict_types=1);

namespace HolyMD\Tests\Config;

use HolyMD\Config\SiteTimezone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteTimezoneTest extends TestCase
{
    public function test_defaults_to_singapore(): void
    {
        self::assertSame('Asia/Singapore', SiteTimezone::fromValue(null)->identifier());
        self::assertSame('Asia/Singapore', SiteTimezone::fromValue('')->identifier());
    }

    public function test_accepts_a_real_php_timezone_identifier(): void
    {
        self::assertSame('Europe/Paris', SiteTimezone::fromValue('Europe/Paris')->identifier());
    }

    public function test_rejects_an_invalid_timezone_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HOLYMD_TIMEZONE');

        SiteTimezone::fromValue('Mars/Olympus');
    }
}
