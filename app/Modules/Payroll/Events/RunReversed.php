<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

/** A reversal run was approved: the original run's payslips are offset. */
final class RunReversed extends PayrollEvent
{
    public static function name(): string
    {
        return 'payroll.run.reversed';
    }
}
