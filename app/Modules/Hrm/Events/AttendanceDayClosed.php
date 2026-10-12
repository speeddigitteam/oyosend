<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

/** An employee's attendance day was closed or recomputed after closing (`revision` increases). */
final class AttendanceDayClosed extends HrmEvent
{
    public static function name(): string
    {
        return 'hrm.attendance.day_closed';
    }
}
