<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

enum AppBillingType: string
{
    case Free = 'free';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case UsageBased = 'usage_based';
    case Enterprise = 'enterprise';

    public function isRecurring(): bool
    {
        return $this === self::Monthly || $this === self::Yearly;
    }

    public function isSelfServe(): bool
    {
        return in_array($this, [self::Free, self::Monthly, self::Yearly, self::OneTime], true);
    }

    public function addPeriodTo(CarbonInterface $date): ?CarbonInterface
    {
        $date = Carbon::instance($date);

        return match ($this) {
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::Yearly => $date->copy()->addYearNoOverflow(),
            default => null,
        };
    }
}
