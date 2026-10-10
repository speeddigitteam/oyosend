<?php

declare(strict_types=1);

namespace App\Core\Billing\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * The end of a period that starts at the given moment.
     */
    public function addTo(CarbonInterface $start): Carbon
    {
        $end = Carbon::instance($start);

        // No overflow: a period that starts on the 31st ends on the last day of a shorter month.
        return match ($this) {
            self::Monthly => $end->addMonthNoOverflow(),
            self::Yearly => $end->addYearNoOverflow(),
        };
    }

    /**
     * The start of a period that ends at the given moment.
     */
    public function subtractFrom(CarbonInterface $end): Carbon
    {
        $start = Carbon::instance($end);

        return match ($this) {
            self::Monthly => $start->subMonthNoOverflow(),
            self::Yearly => $start->subYearNoOverflow(),
        };
    }

    /**
     * Months in one period - what prices of different intervals are compared by.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }
}
