<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Calculation\PayMath;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payroll reports over approved (locked) payslips. Reversal payslips count negatively, so a
 * reversed run nets to zero. Sums are computed in SQL and re-read as exact integers.
 */
final class PayrollReports
{
    /**
     * The periods a report covers: one period, or every period that lies inside `from`..`to`.
     *
     * @return list<string>
     */
    public function periodIds(?string $periodId, ?string $from, ?string $to): array
    {
        if ($periodId !== null && $periodId !== '') {
            return Period::query()->whereKey($periodId)->pluck('id')->all();
        }

        return Period::query()
            ->when($from !== null, fn ($query) => $query->where('starts_on', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('ends_on', '<=', $to))
            ->pluck('id')->all();
    }

    /**
     * Payslip register rows.
     *
     * @param  list<string>  $periodIds
     * @return Builder<Payslip>
     */
    public function register(array $periodIds, ?string $departmentId = null): Builder
    {
        return Payslip::query()
            ->whereIn('period_id', $periodIds)
            ->whereNotNull('locked_at')
            ->when($departmentId !== null && $departmentId !== '', fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('period_id')->orderBy('employee_name')->orderBy('id');
    }

    /**
     * Cost per department (the department name on the payslip at the time).
     *
     * @param  list<string>  $periodIds
     * @return list<array{department: string|null, employees: int, payslips: int, gross_amount: int, deduction_amount: int, reimbursement_amount: int, net_amount: int, employer_amount: int}>
     */
    public function departmentCost(array $periodIds): array
    {
        $signed = static fn (string $column): string => "SUM(CASE WHEN is_reversal = 1 THEN -CAST({$column} AS SIGNED) ELSE CAST({$column} AS SIGNED) END)";

        $rows = Payslip::query()
            ->whereIn('period_id', $periodIds)
            ->whereNotNull('locked_at')
            ->selectRaw('department_name, COUNT(DISTINCT employee_id) AS employees, COUNT(*) AS payslips, '
                .$signed('gross_amount').' AS gross, '.$signed('deduction_amount').' AS deductions, '
                .$signed('reimbursement_amount').' AS reimbursements, '.$signed('net_amount').' AS net, '
                .$signed('employer_amount').' AS employer')
            ->groupBy('department_name')
            ->orderBy('department_name')
            ->toBase()
            ->get();

        return $rows->map(static fn (object $row): array => [
            'department' => $row->department_name,
            'employees' => (int) $row->employees,
            'payslips' => (int) $row->payslips,
            'gross_amount' => PayMath::toInt($row->gross),
            'deduction_amount' => PayMath::toInt($row->deductions),
            'reimbursement_amount' => PayMath::toInt($row->reimbursements),
            'net_amount' => PayMath::toInt($row->net),
            'employer_amount' => PayMath::toInt($row->employer),
        ])->all();
    }

    /**
     * Deductions grouped by line code and name, across the periods.
     *
     * @param  list<string>  $periodIds
     * @return list<array{code: string, name: string, source: string, kind: string, employees: int, amount: int}>
     */
    public function deductions(array $periodIds, string $kind = 'deduction'): array
    {
        $rows = PayslipLine::query()
            ->join('payroll_payslips as slips', function ($join): void {
                $join->on('slips.id', '=', 'payroll_payslip_lines.payslip_id')
                    ->on('slips.tenant_id', '=', 'payroll_payslip_lines.tenant_id');
            })
            ->whereIn('slips.period_id', $periodIds)
            ->whereNotNull('slips.locked_at')
            ->where('payroll_payslip_lines.kind', $kind)
            ->selectRaw('payroll_payslip_lines.code AS code, MIN(payroll_payslip_lines.name) AS name, MIN(payroll_payslip_lines.source) AS source, '
                .'COUNT(DISTINCT slips.employee_id) AS employees, '
                .'SUM(CASE WHEN slips.is_reversal = 1 THEN -CAST(payroll_payslip_lines.amount AS SIGNED) ELSE CAST(payroll_payslip_lines.amount AS SIGNED) END) AS amount')
            ->groupBy('payroll_payslip_lines.code')
            ->orderBy('payroll_payslip_lines.code')
            ->toBase()
            ->get();

        return $rows->map(static fn (object $row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'source' => (string) $row->source,
            'kind' => $kind,
            'employees' => (int) $row->employees,
            'amount' => PayMath::toInt($row->amount),
        ])->all();
    }

    /**
     * Grand totals of the register for the periods.
     *
     * @param  list<string>  $periodIds
     * @return array{payslips: int, gross_amount: int, deduction_amount: int, reimbursement_amount: int, net_amount: int, employer_amount: int}
     */
    public function totals(array $periodIds): array
    {
        $cost = $this->departmentCost($periodIds);
        $sum = static fn (string $key): int => PayMath::sum(array_column($cost, $key));

        return [
            'payslips' => $sum('payslips'),
            'gross_amount' => $sum('gross_amount'),
            'deduction_amount' => $sum('deduction_amount'),
            'reimbursement_amount' => $sum('reimbursement_amount'),
            'net_amount' => $sum('net_amount'),
            'employer_amount' => $sum('employer_amount'),
        ];
    }
}
