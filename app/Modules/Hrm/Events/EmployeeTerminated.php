<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

/** An employee was terminated. */
final class EmployeeTerminated extends HrmEvent
{
    public static function name(): string
    {
        return 'hrm.employee.terminated';
    }
}
