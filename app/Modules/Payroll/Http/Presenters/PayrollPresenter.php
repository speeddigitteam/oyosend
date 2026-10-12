<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Presenters;

use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\RuleSet;
use App\Modules\Payroll\Models\RuleSlab;
use App\Modules\Payroll\Models\Run;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\StructureComponent;

/**
 * The array shapes Payroll pages receive. Keys are camelCase; money is integer minor units;
 * dates are `Y-m-d`, instants ISO 8601 UTC.
 */
final class PayrollPresenter
{
    /** @return array{id: string, number: string, name: string, department: string|null, designation: string|null, status: string}|null */
    public static function employee(?EmployeeSnapshot $employee): ?array
    {
        return $employee === null ? null : [
            'id' => $employee->id,
            'number' => $employee->number,
            'name' => $employee->name,
            'department' => $employee->departmentName,
            'designation' => $employee->designationName,
            'status' => $employee->status,
        ];
    }

    /** @return array<string, mixed> */
    public static function period(Period $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'kind' => $period->kind->value,
            'startsOn' => $period->starts_on->toDateString(),
            'endsOn' => $period->ends_on->toDateString(),
            'payDate' => $period->pay_date?->toDateString(),
            'status' => $period->status->value,
            'closedAt' => $period->closed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function run(Run $run): array
    {
        return [
            'id' => $run->id,
            'number' => $run->number,
            'kind' => $run->kind->value,
            'status' => $run->status->value,
            'periodId' => $run->period_id,
            'periodName' => $run->relationLoaded('period') ? $run->period->name : null,
            'reversesRunId' => $run->reverses_run_id,
            'currency' => $run->currency,
            'employeeCount' => $run->employee_count,
            'grossAmount' => $run->gross_amount,
            'deductionAmount' => $run->deduction_amount,
            'reimbursementAmount' => $run->reimbursement_amount,
            'netAmount' => $run->net_amount,
            'employerAmount' => $run->employer_amount,
            'preparedById' => $run->prepared_by_id,
            'submittedById' => $run->submitted_by_id,
            'approvedById' => $run->approved_by_id,
            'submittedAt' => $run->submitted_at?->toIso8601String(),
            'approvedAt' => $run->approved_at?->toIso8601String(),
            'isLocked' => $run->isLocked(),
            'note' => $run->note,
            'createdAt' => $run->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function payslipRow(Payslip $payslip): array
    {
        return [
            'id' => $payslip->id,
            'number' => $payslip->number,
            'runId' => $payslip->run_id,
            'periodId' => $payslip->period_id,
            'periodName' => $payslip->relationLoaded('period') ? $payslip->period->name : null,
            'employeeId' => $payslip->employee_id,
            'employeeNumber' => $payslip->employee_number,
            'employeeName' => $payslip->employee_name,
            'departmentName' => $payslip->department_name,
            'designationName' => $payslip->designation_name,
            'currency' => $payslip->currency,
            'basicAmount' => $payslip->basic_amount,
            'grossAmount' => $payslip->gross_amount,
            'deductionAmount' => $payslip->deduction_amount,
            'reimbursementAmount' => $payslip->reimbursement_amount,
            'netAmount' => $payslip->net_amount,
            'employerAmount' => $payslip->employer_amount,
            'isReversal' => $payslip->is_reversal,
            'isLocked' => $payslip->isLocked(),
            'paymentStatus' => $payslip->payment_status->value,
            'paymentMethod' => $payslip->payment_method?->value,
            'paymentReference' => $payslip->payment_reference,
            'paidOn' => $payslip->paid_on?->toDateString(),
            'warnings' => $payslip->warnings ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public static function payslipLine(PayslipLine $line): array
    {
        return [
            'id' => $line->id,
            'kind' => $line->kind->value,
            'code' => $line->code,
            'name' => $line->source === 'system' ? __($line->name) : $line->name,
            'source' => $line->source,
            'amount' => $line->amount,
            'isTaxable' => $line->is_taxable,
            'detail' => $line->detail,
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'method' => $payment->method?->value,
            'reference' => $payment->reference,
            'paidOn' => $payment->paid_on?->toDateString(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'note' => $payment->note,
            'createdAt' => $payment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function structure(SalaryStructure $structure): array
    {
        return [
            'id' => $structure->id,
            'name' => $structure->name,
            'code' => $structure->code,
            'description' => $structure->description,
            'isActive' => $structure->is_active,
            'componentCount' => $structure->relationLoaded('components') ? $structure->components->count() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function component(StructureComponent $component): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'code' => $component->code,
            'kind' => $component->kind->value,
            'calc' => $component->calc->value,
            'amount' => $component->amount,
            'rateBps' => $component->rate_bps,
            'isTaxable' => $component->is_taxable,
            'sortOrder' => $component->sort_order,
            'effectiveFrom' => $component->effective_from?->toDateString(),
            'effectiveTo' => $component->effective_to?->toDateString(),
            'isActive' => $component->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function assignment(SalaryAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'revision' => $assignment->revision,
            'structureId' => $assignment->structure_id,
            'structureName' => $assignment->relationLoaded('structure') ? $assignment->structure->name : null,
            'basicAmount' => $assignment->basic_amount,
            'currency' => $assignment->currency,
            'effectiveFrom' => $assignment->effective_from->toDateString(),
            'reason' => $assignment->reason,
            'createdAt' => $assignment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function account(Profile $profile): array
    {
        return [
            'paymentMethod' => $profile->payment_method?->value,
            'accountHolder' => $profile->account_holder,
            'bankName' => $profile->bank_name,
            'bankBranch' => $profile->bank_branch,
            'routingNumber' => $profile->routing_number,
            'accountNumberMasked' => $profile->maskedAccountNumber(),
            'hasAccountNumber' => $profile->account_number !== null && $profile->account_number !== '',
        ];
    }

    /** @return array<string, mixed> */
    public static function item(Item $item): array
    {
        return [
            'id' => $item->id,
            'employeeId' => $item->employee_id,
            'periodId' => $item->period_id,
            'periodName' => $item->relationLoaded('period') ? $item->period->name : null,
            'type' => $item->type->value,
            'name' => $item->name,
            'amount' => $item->amount,
            'basicBps' => $item->basic_bps,
            'isTaxable' => $item->is_taxable,
            'status' => $item->status->value,
            'payslipId' => $item->payslip_id,
            'note' => $item->note,
        ];
    }

    /** @return array<string, mixed> */
    public static function loan(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'employeeId' => $loan->employee_id,
            'kind' => $loan->kind->value,
            'title' => $loan->title,
            'principalAmount' => $loan->principal_amount,
            'installmentCount' => $loan->installment_count,
            'firstDueOn' => $loan->first_due_on->toDateString(),
            'currency' => $loan->currency,
            'status' => $loan->status->value,
            'reason' => $loan->reason,
            'decisionNote' => $loan->decision_note,
            'requestedById' => $loan->requested_by_id,
            'approvedById' => $loan->approved_by_id,
            'decidedAt' => $loan->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function installment(LoanInstallment $installment): array
    {
        return [
            'id' => $installment->id,
            'sequence' => $installment->sequence,
            'dueOn' => $installment->due_on->toDateString(),
            'amount' => $installment->amount,
            'status' => $installment->status->value,
            'payslipId' => $installment->payslip_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function ruleSet(RuleSet $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'code' => $rule->code,
            'country' => $rule->country,
            'engine' => $rule->engine,
            'category' => $rule->category->value,
            'payer' => $rule->payer->value,
            'basis' => $rule->basis->value,
            'method' => $rule->method->value,
            'annualize' => $rule->annualize,
            'periodsPerYear' => $rule->periods_per_year,
            'minAmount' => $rule->min_amount,
            'maxAmount' => $rule->max_amount,
            'employmentTypes' => $rule->employment_types ?? [],
            'effectiveFrom' => $rule->effective_from->toDateString(),
            'effectiveTo' => $rule->effective_to?->toDateString(),
            'isActive' => $rule->is_active,
            'version' => $rule->version,
            'notes' => $rule->notes,
            'slabs' => $rule->relationLoaded('slabs')
                ? $rule->slabs->map(static fn (RuleSlab $slab): array => [
                    'position' => $slab->position,
                    'from' => $slab->from_amount,
                    'to' => $slab->to_amount,
                    'rateBps' => $slab->rate_bps,
                    'fixed' => $slab->fixed_amount,
                ])->all()
                : [],
        ];
    }
}
