<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Events;

/** An employee record was created. */
final class EmployeeCreated extends HrmEvent
{
    public static function name(): string
    {
        return 'hrm.employee.created';
    }
}
