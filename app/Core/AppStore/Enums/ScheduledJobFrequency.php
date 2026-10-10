<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

use Carbon\CarbonImmutable;

/** Supported, deliberately small scheduling surface for first-party apps. */
enum ScheduledJobFrequency: string
{
    case EveryMinute = 'every_minute';
    case Hourly = 'hourly';
    case Daily = 'daily';

    public function isDue(CarbonImmutable $now): bool
    {
        return match ($this) {
            self::EveryMinute => true,
            self::Hourly => $now->minute === 0,
            self::Daily => $now->hour === 0 && $now->minute === 0,
        };
    }

    /** A stable idempotency bucket in the tenant's local timezone. */
    public function slot(CarbonImmutable $now): string
    {
        return match ($this) {
            self::EveryMinute => $now->format('YmdHi'),
            self::Hourly => $now->format('YmdH'),
            self::Daily => $now->format('Ymd'),
        };
    }
}
