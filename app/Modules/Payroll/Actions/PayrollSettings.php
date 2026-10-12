<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Payroll\Calculation\CalcSettings;
use App\Modules\Payroll\Models\PayrollSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace payroll settings: day valuation, overtime, approval policy and numbering. */
final class PayrollSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** The row for the active workspace; created with defaults on first use. */
    public function current(): PayrollSetting
    {
        $existing = PayrollSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return PayrollSetting::query()->create([])->refresh();
        } catch (UniqueConstraintViolationException) {
            return PayrollSetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): PayrollSetting
    {
        return DB::transaction(function () use ($attributes): PayrollSetting {
            $settings = $this->current();
            $before = $settings->only(['require_separate_approver', 'days_basis', 'fixed_days', 'daily_rate_base']);
            $settings->fill($attributes)->save();
            $this->audit->record('payroll.settings.saved', $settings, [
                'before' => $before,
                'after' => $settings->only(['require_separate_approver', 'days_basis', 'fixed_days', 'daily_rate_base']),
            ]);

            return $settings;
        });
    }

    public function calc(): CalcSettings
    {
        $settings = $this->current();

        return new CalcSettings(
            daysBasis: $settings->days_basis->value,
            fixedDays: $settings->fixed_days,
            dailyRateBase: $settings->daily_rate_base->value,
            deductUnpaidLeave: $settings->deduct_unpaid_leave,
            deductAbsence: $settings->deduct_absence,
            workDayMinutes: $settings->work_day_minutes,
            overtimeMultiplierBps: $settings->overtime_multiplier_bps,
            overtimeTaxable: $settings->overtime_taxable,
            maxOvertimeMinutes: $settings->max_overtime_minutes,
        );
    }

    /** The workspace currency (ISO 4217): every payroll amount is in it. */
    public function currency(): string
    {
        return strtoupper($this->context->require()->currency);
    }

    /** Today's date in the workspace timezone. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->require()->timezone)->startOfDay();
    }
}
