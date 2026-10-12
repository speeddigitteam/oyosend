<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Widgets;

use App\Core\Identity\Models\User;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;

/** Workspace payroll status, shown with `payroll.runs.view`. */
final class PayrollOverviewWidget
{
    public function __construct(private readonly PayrollSettings $settings) {}

    /**
     * @return array{currency: string, openPeriods: int, awaitingApproval: int, unpaidPayslips: int, pendingLoans: int, latestRun: array{id: string, number: string|null, periodName: string, status: string, netAmount: int, employeeCount: int}|null}
     */
    public function __invoke(User $user): array
    {
        $latest = Run::query()->with('period:id,name')->orderByDesc('created_at')->orderByDesc('id')->first();

        return [
            'currency' => $this->settings->currency(),
            'openPeriods' => Period::query()->whereIn('status', [PeriodStatus::Open, PeriodStatus::Processing])->count(),
            'awaitingApproval' => Run::query()->where('status', RunStatus::Submitted)->count(),
            'unpaidPayslips' => Payslip::query()->whereNotNull('locked_at')->where('is_reversal', false)
                ->where('payment_status', '!=', PaymentStatus::Paid)->count(),
            'pendingLoans' => Loan::query()->where('status', LoanStatus::Pending)->count(),
            'latestRun' => $latest === null ? null : [
                'id' => $latest->id,
                'number' => $latest->number,
                'periodName' => $latest->period->name,
                'status' => $latest->status->value,
                'netAmount' => $latest->net_amount,
                'employeeCount' => $latest->employee_count,
            ],
        ];
    }
}
