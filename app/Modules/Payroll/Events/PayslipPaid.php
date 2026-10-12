<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

/** A payslip was recorded as paid. */
final class PayslipPaid extends PayrollEvent
{
    public static function name(): string
    {
        return 'payroll.payslip.paid';
    }
}
