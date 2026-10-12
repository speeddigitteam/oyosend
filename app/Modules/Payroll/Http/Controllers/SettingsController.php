<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Calculation\RuleSetEngines;
use App\Modules\Payroll\Enums\DaysBasis;
use App\Modules\Payroll\Enums\RateBase;
use App\Modules\Payroll\Http\Requests\SettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Day valuation, overtime, approval policy and numbering (`payroll.settings.manage`). */
final class SettingsController
{
    public function __construct(
        private readonly PayrollSettings $settings,
        private readonly RuleSetEngines $engines,
    ) {}

    public function index(): Response
    {
        $settings = $this->settings->current();

        return Inertia::render('Modules/Payroll/Pages/Settings/Index', [
            'settings' => [
                'daysBasis' => $settings->days_basis->value,
                'fixedDays' => $settings->fixed_days,
                'dailyRateBase' => $settings->daily_rate_base->value,
                'deductUnpaidLeave' => $settings->deduct_unpaid_leave,
                'deductAbsence' => $settings->deduct_absence,
                'workDayMinutes' => $settings->work_day_minutes,
                'overtimeMultiplierBps' => $settings->overtime_multiplier_bps,
                'overtimeTaxable' => $settings->overtime_taxable,
                'maxOvertimeMinutes' => $settings->max_overtime_minutes,
                'requireSeparateApprover' => $settings->require_separate_approver,
                'payslipPrefix' => $settings->payslip_prefix,
                'runPrefix' => $settings->run_prefix,
                'numberPadding' => $settings->number_padding,
                'payslipNote' => $settings->payslip_note,
            ],
            'currency' => $this->settings->currency(),
            'options' => [
                'daysBasis' => DaysBasis::values(),
                'rateBases' => RateBase::values(),
                'engines' => $this->engines->keys(),
            ],
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $this->settings->update($request->validated());
        Toast::success(__('Payroll settings saved.'));

        return back();
    }
}
