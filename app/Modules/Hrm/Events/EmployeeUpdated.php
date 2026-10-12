<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

/** Placement or contact details of an employee changed (the payload lists the changed field names). */
final class EmployeeUpdated extends HrmEvent
{
    public static function name(): string
    {
        return 'hrm.employee.updated';
    }
}
