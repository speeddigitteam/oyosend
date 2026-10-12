<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/** How a saved report picks its dates: the last N days up to today, or two fixed dates. */
enum RangeMode: string
{
    case LastDays = 'last_days';
    case Fixed = 'fixed';
}
