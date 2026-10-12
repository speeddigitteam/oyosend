<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

/** The workspace's pay-day rules for one calculation (a plain copy of the settings row). */
final readonly class CalcSettings
{
    public function __construct(
        /** `calendar_days` (days of the period) or `fixed_days` (a fixed divisor). */
        public string $daysBasis = 'calendar_days',
        public int $fixedDays = 30,
        /** `gross` (basic plus recurring earnings) or `basic`: what a day of unpaid time is valued from. */
        public string $dailyRateBase = 'gross',
        public bool $deductUnpaidLeave = true,
        public bool $deductAbsence = true,
        public int $workDayMinutes = 480,
        public int $overtimeMultiplierBps = 15_000,
        public bool $overtimeTaxable = true,
        public ?int $maxOvertimeMinutes = null,
    ) {}
}
