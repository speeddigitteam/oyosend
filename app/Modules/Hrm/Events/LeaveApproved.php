<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

/** A leave request completed its approval steps. */
final class LeaveApproved extends HrmEvent
{
    public static function name(): string
    {
        return 'hrm.leave.approved';
    }
}
