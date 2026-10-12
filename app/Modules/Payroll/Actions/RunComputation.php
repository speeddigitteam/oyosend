<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Contracts\EmployeeTimeSummary;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Calculation\EmployeeInput;
use App\Modules\Payroll\Calculation\PayCalculator;
use App\Modules\Payroll\Calculation\PayMath;
use App\Modules\Payroll\Calculation\PayslipDraft;
use App\Modules\Payroll\Enums\ComponentCalc;
use App\Modules\Payroll\Enums\ComponentKind;
use App\Modules\Payroll\Enums\InstallmentStatus;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\ItemType;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\StructureComponent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RangeException;

/**
 * Gathers a period's inputs (employees and time through the HRM contracts, salaries, one-off items,
 * loan installments, rule sets) and runs the pure calculator over them.
 */
final class RunComputation
{
    public function __construct(
        private readonly EmployeeDirectory $directory,
        private readonly EmployeeTimeSummary $time,
        private readonly PayCalculator $calculator,
        private readonly PayrollSettings $settings,
        private readonly RuleSetManager $rules,
    ) {}

    /**
     * The regular run of a period. Refuses (throws) when the period has not ended or an employee
     * still has attendance days that are not closed.
     */
    public function regular(Period $period): ComputedRun
    {
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        if ($to >= $this->settings->today()->toDateString()) {
            throw ValidationException::withMessages(['period' => __('A period can be calculated only after its last day has passed.')]);
        }

        $currency = $this->settings->currency();
        $calc = $this->settings->calc();
        $employees = $this->employees($from, $to);
        $ids = array_keys($employees);

        $assignments = $this->assignments($ids, $to);
        $items = Item::query()->where('period_id', $period->id)->where('status', ItemStatus::Pending)->get()->groupBy('employee_id');
        $installments = $this->installments($to);
        $definitions = $this->rules->definitionsFor($from, $to);

        $payslips = [];
        $errors = [];
        $warnings = [];
        $open = [];

        foreach ($employees as $id => $employee) {
            $assignment = $assignments->get($id);

            if ($assignment === null) {
                $warnings[] = ['code' => 'no_salary_assignment', 'employee_id' => $id, 'employee_name' => $employee->name];

                continue;
            }

            if ($assignment->currency !== $currency) {
                $errors[] = ['code' => 'currency_mismatch', 'employee_id' => $id, 'employee_name' => $employee->name];

                continue;
            }

            $attendance = $this->time->attendance($id, $from, $to);

            if ($attendance['open_days'] > 0) {
                $open[] = ['employee_id' => $id, 'employee_name' => $employee->name, 'open_days' => $attendance['open_days']];

                continue;
            }

            $input = new EmployeeInput(
                employeeId: $id,
                number: $employee->number,
                name: $employee->name,
                employmentType: $employee->employmentType,
                joinedOn: $employee->joinedOn,
                lastWorkingOn: $employee->lastWorkingOn ?? $employee->terminatedOn,
                basicAmount: $assignment->basic_amount,
                currency: $currency,
                assignmentId: $assignment->id,
                structureName: $assignment->structure->name,
                components: $this->components($assignment, $from, $to),
                attendance: $attendance,
                leave: $this->time->leaveUsage($id, $from, $to),
                items: $this->itemRows($items->get($id)),
                installments: $this->installmentRows($installments->get($id)),
                rules: $definitions,
            );

            try {
                $draft = $this->calculator->calculate($input, $from, $to, $calc);
            } catch (RangeException) {
                // An amount outside the supported range blocks submission instead of failing the request.
                $errors[] = ['code' => 'amount_out_of_range', 'employee_id' => $id, 'employee_name' => $employee->name];

                continue;
            }

            if ($draft->payableDays === 0) {
                continue;
            }

            if ($draft->error !== null) {
                $errors[] = ['code' => $draft->error, 'employee_id' => $id, 'employee_name' => $employee->name];

                continue;
            }

            $payslips[] = ['employee' => $employee, 'draft' => $draft, 'assignment_id' => $assignment->id, 'structure_name' => $assignment->structure->name];

            foreach ($draft->warnings as $warning) {
                $warnings[] = [...$warning, 'employee_id' => $id, 'employee_name' => $employee->name];
            }
        }

        if ($open !== []) {
            $names = array_slice(array_column($open, 'employee_name'), 0, 5);
            $more = count($open) - count($names);

            throw ValidationException::withMessages(['period' => __('Attendance days are still open for :names. Close or correct them before running payroll.', [
                'names' => implode(', ', $names).($more > 0 ? ' '.__('and :count more', ['count' => $more]) : ''),
            ])]);
        }

        // Pending items of people who are not paid in this run stay pending: say so.
        $paid = array_flip(array_map(static fn (array $row): string => $row['employee']->id, $payslips));

        foreach ($items as $employeeId => $rows) {
            if (! isset($paid[$employeeId])) {
                $warnings[] = ['code' => 'items_not_applied', 'employee_id' => $employeeId, 'count' => $rows->count()];
            }
        }

        usort($payslips, static fn (array $a, array $b): int => [$a['employee']->name, $a['employee']->id] <=> [$b['employee']->name, $b['employee']->id]);

        return new ComputedRun($payslips, $errors, $warnings);
    }

    /**
     * An adjustment run: only the items that are still pending for an already approved period.
     * No basic salary, attendance, rule sets or loans are applied.
     */
    public function adjustment(Period $period): ComputedRun
    {
        $to = $period->ends_on->toDateString();
        $items = Item::query()->where('period_id', $period->id)->where('status', ItemStatus::Pending)->get()->groupBy('employee_id');
        $employees = $this->directory->findMany($items->keys()->all());
        $assignments = $this->assignments($items->keys()->all(), $to);
        $payslips = [];
        $errors = [];

        foreach ($items as $employeeId => $rows) {
            $employee = $employees[$employeeId] ?? null;

            if ($employee === null) {
                continue;
            }

            $basic = $assignments->get($employeeId)?->basic_amount;
            $lines = [];
            $earnings = 0;
            $taxable = 0;
            $reimbursement = 0;
            $deductions = 0;
            $itemIds = [];
            $failed = null;

            foreach ($rows as $item) {
                if ($item->basic_bps !== null && $basic === null) {
                    $failed = 'no_salary_assignment';

                    break;
                }

                try {
                    $amount = $item->basic_bps !== null ? PayMath::scale((int) $basic, $item->basic_bps, PayMath::BPS) : (int) $item->amount;
                } catch (RangeException) {
                    $failed = 'amount_out_of_range';

                    break;
                }

                if ($amount < 1) {
                    continue;
                }

                $itemIds[] = $item->id;

                if ($item->type === ItemType::Deduction) {
                    $deductions += $amount;
                    $kind = 'deduction';
                } elseif ($item->type === ItemType::Reimbursement) {
                    $reimbursement += $amount;
                    $kind = 'reimbursement';
                } else {
                    $earnings += $amount;
                    $taxable += $item->is_taxable ? $amount : 0;
                    $kind = 'earning';
                }

                $lines[] = [
                    'kind' => $kind, 'code' => $item->type->value, 'name' => $item->name, 'source' => 'item', 'source_id' => $item->id,
                    'amount' => $amount, 'is_taxable' => $item->is_taxable, 'detail' => null,
                ];
            }

            $net = $earnings + $reimbursement - $deductions;
            $failed ??= $net < 0 ? 'negative_net' : null;

            if ($failed !== null) {
                $errors[] = ['code' => $failed, 'employee_id' => $employeeId, 'employee_name' => $employee->name];

                continue;
            }

            if ($lines === []) {
                continue;
            }

            $payslips[] = [
                'employee' => $employee,
                'draft' => new PayslipDraft(
                    employeeId: $employeeId,
                    payableDays: 0,
                    periodDays: 0,
                    basicAmount: 0,
                    grossAmount: $earnings,
                    taxableAmount: $taxable,
                    deductionAmount: $deductions,
                    reimbursementAmount: $reimbursement,
                    netAmount: $net,
                    employerAmount: 0,
                    lines: $lines,
                    warnings: [],
                    itemIds: $itemIds,
                    installmentIds: [],
                    attendance: [],
                ),
                'assignment_id' => null,
                'structure_name' => null,
            ];
        }

        usort($payslips, static fn (array $a, array $b): int => [$a['employee']->name, $a['employee']->id] <=> [$b['employee']->name, $b['employee']->id]);

        return new ComputedRun($payslips, $errors, []);
    }

    /**
     * Everyone employed on any day of the period, read through the directory contract, cursor-paged.
     *
     * @return array<string, EmployeeSnapshot>
     */
    private function employees(string $from, string $to): array
    {
        $found = [];
        $after = null;

        do {
            $page = $this->directory->list([], 500, $after);

            foreach ($page as $employee) {
                $after = $employee->id;
                $last = $employee->lastWorkingOn ?? $employee->terminatedOn;

                if ($employee->joinedOn <= $to && ($last === null || $last >= $from)) {
                    $found[$employee->id] = $employee;
                }
            }
        } while (count($page) === 500);

        return $found;
    }

    /**
     * The salary revision in force on `$date` per employee.
     *
     * @param  list<string>  $employeeIds
     * @return Collection<string, SalaryAssignment>
     */
    private function assignments(array $employeeIds, string $date): Collection
    {
        $result = collect();

        foreach (array_chunk($employeeIds, 500) as $chunk) {
            $rows = SalaryAssignment::query()
                ->with('structure.components')
                ->whereIn('employee_id', $chunk)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')
                ->get();

            foreach ($rows as $row) {
                if (! $result->has($row->employee_id)) {
                    $result->put($row->employee_id, $row);
                }
            }
        }

        return $result;
    }

    /** @return Collection<array-key, EloquentCollection<int, LoanInstallment>> */
    private function installments(string $to): Collection
    {
        return LoanInstallment::query()
            ->with('loan:id,title,kind,status')
            ->where('status', InstallmentStatus::Pending)
            ->where('due_on', '<=', $to)
            ->whereHas('loan', fn ($query) => $query->where('status', LoanStatus::Active))
            ->get()
            ->groupBy('employee_id');
    }

    /** @return list<array{id: string, code: string, name: string, kind: string, calc: string, amount: int|null, rate_bps: int|null, taxable: bool}> */
    private function components(SalaryAssignment $assignment, string $from, string $to): array
    {
        return $assignment->structure->components
            ->filter(static fn (StructureComponent $component): bool => $component->appliesTo($from, $to))
            ->map(static fn (StructureComponent $component): array => [
                'id' => $component->id,
                'code' => $component->code,
                'name' => $component->name,
                'kind' => $component->kind === ComponentKind::Earning ? 'earning' : 'deduction',
                'calc' => $component->calc === ComponentCalc::Fixed ? 'fixed' : 'percent_basic',
                'amount' => $component->amount,
                'rate_bps' => $component->rate_bps,
                'taxable' => $component->is_taxable,
            ])->values()->all();
    }

    /**
     * @param  Collection<int, Item>|null  $items
     * @return list<array{id: string, type: string, name: string, amount: int|null, basic_bps: int|null, taxable: bool}>
     */
    private function itemRows(?Collection $items): array
    {
        return ($items ?? collect())->map(static fn (Item $item): array => [
            'id' => $item->id,
            'type' => $item->type->value,
            'name' => $item->name,
            'amount' => $item->amount,
            'basic_bps' => $item->basic_bps,
            'taxable' => $item->is_taxable,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, LoanInstallment>|null  $installments
     * @return list<array{id: string, loan_id: string, label: string, sequence: int, due_on: string, amount: int}>
     */
    private function installmentRows(?Collection $installments): array
    {
        return ($installments ?? collect())->map(static fn (LoanInstallment $installment): array => [
            'id' => $installment->id,
            'loan_id' => $installment->loan_id,
            'label' => $installment->loan->title.' ('.$installment->sequence.')',
            'sequence' => $installment->sequence,
            'due_on' => $installment->due_on->toDateString(),
            'amount' => $installment->amount,
        ])->values()->all();
    }
}
