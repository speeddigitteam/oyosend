<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Organization\Models\Department;
use App\Modules\Payroll\Actions\PaymentManager;
use App\Modules\Payroll\Actions\PayrollReports;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\ReportRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\Run;
use App\Modules\Payroll\Support\CsvNumber;
use App\Modules\Payroll\Support\SafeCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll register, department cost, deductions summary and the bank / payment sheet. All
 * workspace-wide: `payroll.reports.view` for reports, `payroll.payments.export` for the sheet.
 */
final class ReportController
{
    public function __construct(
        private readonly PayrollReports $reports,
        private readonly AuditLogger $audit,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(ReportRequest $request): Response
    {
        $filters = $request->validated();
        $today = $this->support->today();
        $from = $filters['from'] ?? $today->startOfYear()->toDateString();
        $to = $filters['to'] ?? $today->endOfYear()->toDateString();
        $periodId = $filters['period_id'] ?? null;
        $department = $filters['department_id'] ?? null;
        $ids = $this->reports->periodIds($periodId, $periodId === null ? $from : null, $periodId === null ? $to : null);
        $currency = $this->support->currency();

        return Inertia::render('Modules/Payroll/Pages/Reports/Index', [
            'filters' => ['period_id' => $periodId ?? '', 'from' => $from, 'to' => $to, 'department_id' => $department ?? ''],
            'periods' => Period::query()->orderByDesc('starts_on')->limit(60)->get()
                ->map(static fn (Period $period): array => PayrollPresenter::period($period))->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Department $item): array => ['id' => $item->id, 'name' => $item->name])->all(),
            'currency' => $currency,
            'totals' => $this->reports->totals($ids),
            'register' => $this->reports->register($ids, $department)->with('period:id,name')->paginate(50)->withQueryString()
                ->through(static fn (Payslip $payslip): array => PayrollPresenter::payslipRow($payslip)),
            'departmentCost' => $this->reports->departmentCost($ids),
            'deductions' => $this->translated($this->reports->deductions($ids)),
            'employerContributions' => $this->translated($this->reports->deductions($ids, 'employer')),
        ]);
    }

    /** CSV export of one report (`report` = register, departments, deductions or employer). */
    public function export(ReportRequest $request): HttpResponse
    {
        $filters = $request->validated();
        $report = $filters['report'] ?? 'register';
        $today = $this->support->today();
        $periodId = $filters['period_id'] ?? null;
        $from = $filters['from'] ?? $today->startOfYear()->toDateString();
        $to = $filters['to'] ?? $today->endOfYear()->toDateString();
        $ids = $this->reports->periodIds($periodId, $periodId === null ? $from : null, $periodId === null ? $to : null);
        $currency = $this->support->currency();

        $csv = match ($report) {
            'departments' => SafeCsv::render(
                [__('Department'), __('Employees'), __('Payslips'), __('Gross pay'), __('Deductions'), __('Reimbursements'), __('Net pay'), __('Employer cost')],
                array_map(static fn (array $row): array => [
                    $row['department'] ?? __('No department'), $row['employees'], $row['payslips'],
                    SafeCsv::number($row['gross_amount'], $currency), SafeCsv::number($row['deduction_amount'], $currency),
                    SafeCsv::number($row['reimbursement_amount'], $currency), SafeCsv::number($row['net_amount'], $currency),
                    SafeCsv::number($row['employer_amount'], $currency),
                ], $this->reports->departmentCost($ids)),
            ),
            'deductions', 'employer' => SafeCsv::render(
                [__('Code'), __('Name'), __('Type'), __('Employees'), __('Amount')],
                array_map(static fn (array $row): array => [
                    $row['code'], $row['source'] === 'system' ? __($row['name']) : $row['name'], $row['source'],
                    $row['employees'], SafeCsv::number($row['amount'], $currency),
                ], $this->reports->deductions($ids, $report === 'employer' ? 'employer' : 'deduction')),
            ),
            default => SafeCsv::render(
                [__('Payslip'), __('Period'), __('Employee number'), __('Employee'), __('Department'), __('Reversal'), __('Basic'), __('Gross pay'), __('Deductions'), __('Reimbursements'), __('Net pay'), __('Employer cost'), __('Payment status')],
                $this->registerRows($ids, $filters['department_id'] ?? null, $currency),
            ),
        };

        $this->audit->record('payroll.report.exported', null, ['report' => $report, 'periods' => count($ids)]);

        return $this->csvResponse($csv, 'payroll-'.$report.'-'.($periodId ?? $from.'_'.$to).'.csv');
    }

    /** The bank / payment sheet of an approved run: unpaid payslips with the employee's payment account. */
    public function paymentSheet(Request $request, Run $run): HttpResponse
    {
        if (! PaymentManager::isPayable($run)) {
            throw ValidationException::withMessages(['run' => __('A payment sheet is available for approved payroll runs only.')]);
        }

        $payslips = Payslip::query()->where('run_id', $run->id)->where('is_reversal', false)
            ->where('payment_status', '!=', PaymentStatus::Paid)->where('net_amount', '>', 0)
            ->orderBy('employee_name')->orderBy('id')->get();
        $profiles = Profile::query()->whereIn('employee_id', $payslips->pluck('employee_id')->all())->get()->keyBy('employee_id');
        $currency = $run->currency;

        $rows = $payslips->map(static function (Payslip $payslip) use ($profiles, $currency): array {
            $profile = $profiles->get($payslip->employee_id);

            return [
                $payslip->number, $payslip->employee_number, $payslip->employee_name,
                $profile?->payment_method?->value, $profile?->account_holder, $profile?->bank_name, $profile?->bank_branch,
                $profile?->routing_number, $profile?->account_number,
                SafeCsv::number($payslip->net_amount, $currency), $currency,
            ];
        })->all();

        $csv = SafeCsv::render(
            [__('Payslip'), __('Employee number'), __('Employee'), __('Method'), __('Account holder'), __('Bank'), __('Branch'), __('Routing number'), __('Account number'), __('Amount'), __('Currency')],
            $rows,
        );

        $this->audit->record('payroll.payment_sheet.exported', $run, ['rows' => count($rows)]);

        return $this->csvResponse($csv, 'payment-sheet-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($run->number ?? $run->id)).'.csv');
    }

    /**
     * @param  list<string>  $periodIds
     * @return list<list<string|int|CsvNumber|null>>
     */
    private function registerRows(array $periodIds, ?string $departmentId, string $currency): array
    {
        $rows = [];

        foreach ($this->reports->register($periodIds, $departmentId)->with('period:id,name')->lazyById(500) as $payslip) {
            $rows[] = [
                $payslip->number, $payslip->period->name, $payslip->employee_number, $payslip->employee_name,
                $payslip->department_name, $payslip->is_reversal ? __('Yes') : __('No'),
                SafeCsv::number($payslip->basic_amount, $currency), SafeCsv::number($payslip->gross_amount, $currency),
                SafeCsv::number($payslip->deduction_amount, $currency), SafeCsv::number($payslip->reimbursement_amount, $currency),
                SafeCsv::number($payslip->net_amount, $currency), SafeCsv::number($payslip->employer_amount, $currency),
                $payslip->payment_status->value,
            ];
        }

        return $rows;
    }

    private function csvResponse(string $csv, string $filename): HttpResponse
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]/', '-', $filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * System line names are stored as English keys; the page shows them in the viewer's language.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function translated(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (($row['source'] ?? null) === 'system' && is_string($row['name'] ?? null)) {
                $row['name'] = __($row['name']);
            }

            $out[] = $row;
        }

        return $out;
    }
}
