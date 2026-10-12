<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

/** A leave request was submitted. */
final class LeaveRequested extends HrmEvent
{
    public static function name(): string
    {
        return 'hrm.leave.requested';
    }
}
