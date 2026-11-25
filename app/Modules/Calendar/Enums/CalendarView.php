<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

enum CalendarView: string
{
    case Day = 'day';
    case FourDays = 'four_days';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case Schedule = 'schedule';
}
