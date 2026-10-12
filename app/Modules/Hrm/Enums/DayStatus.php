<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum DayStatus: string
{
    case Present = 'present';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case OnLeave = 'on_leave';
    case Holiday = 'holiday';
    case WeeklyOff = 'weekly_off';
    /** Closed with an entry that has no clock-out and nothing else to count. */
    case Incomplete = 'incomplete';
}
