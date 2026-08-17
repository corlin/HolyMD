<?php

declare(strict_types=1);

namespace HolyMD\Config;

use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class SiteTimezone
{
    private function __construct(private DateTimeZone $timezone)
    {
    }

    public static function fromEnvironment(): self
    {
        return self::fromValue(Env::get('HOLYMD_TIMEZONE'));
    }

    public static function fromValue(?string $value): self
    {
        $identifier = trim((string) $value);
        if ($identifier === '') {
            $identifier = 'Asia/Singapore';
        }

        try {
            return new self(new DateTimeZone($identifier));
        } catch (Throwable) {
            throw new InvalidArgumentException('HOLYMD_TIMEZONE must be a valid PHP timezone identifier.');
        }
    }

    public function identifier(): string
    {
        return $this->timezone->getName();
    }

    public function zone(): DateTimeZone
    {
        return $this->timezone;
    }
}
