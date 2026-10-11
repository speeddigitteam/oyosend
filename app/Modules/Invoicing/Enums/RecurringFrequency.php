<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

use Carbon\CarbonImmutable;

enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * The date of the n-th occurrence counted from the anchor. Computed from the anchor
     * every time, so a 31st does not drift to the 28th after February.
     */
    public function occurrence(CarbonImmutable $anchor, int $every, int $occurrence): CarbonImmutable
    {
        $steps = $every * $occurrence;

        return match ($this) {
            self::Weekly => $anchor->addWeeks($steps),
            self::Monthly => $anchor->addMonthsNoOverflow($steps),
            self::Quarterly => $anchor->addMonthsNoOverflow($steps * 3),
            self::Yearly => $anchor->addYearsNoOverflow($steps),
        };
    }
}
