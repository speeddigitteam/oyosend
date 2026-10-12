<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

/** A payroll run (regular or adjustment) was approved and locked; its payslips now exist. */
final class RunApproved extends PayrollEvent
{
    public static function name(): string
    {
        return 'payroll.run.approved';
    }
}
