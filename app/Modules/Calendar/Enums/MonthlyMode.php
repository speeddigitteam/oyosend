<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

/** How a monthly series picks its day: the same date, the same nth weekday, or the last such weekday of the month. */
enum MonthlyMode: string
{
    case Day = 'day';
    case Weekday = 'weekday';
    case LastWeekday = 'last_weekday';
}
