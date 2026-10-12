<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

/**
 * Everything the calculator needs for one employee and one period, as plain data.
 */
final readonly class EmployeeInput
{
    /**
     * @param  int  $basicAmount  The full-period monthly basic of the salary revision in force.
     * @param  list<array{id: string, code: string, name: string, kind: string, calc: string, amount: int|null, rate_bps: int|null, taxable: bool}>  $components
     * @param  array<string, int|string>  $attendance  The `EmployeeTimeSummary::attendance()` array.
     * @param  list<array{leave_type_id: string, code: string, name: string, is_paid: bool, units: int}>  $leave
     * @param  list<array{id: string, type: string, name: string, amount: int|null, basic_bps: int|null, taxable: bool}>  $items
     * @param  list<array{id: string, loan_id: string, label: string, sequence: int, due_on: string, amount: int}>  $installments
     * @param  list<RuleDefinition>  $rules
     */
    public function __construct(
        public string $employeeId,
        public string $number,
        public string $name,
        public string $employmentType,
        public string $joinedOn,
        public ?string $lastWorkingOn,
        public int $basicAmount,
        public string $currency,
        public ?string $assignmentId,
        public ?string $structureName,
        public array $components,
        public array $attendance,
        public array $leave,
        public array $items,
        public array $installments,
        public array $rules,
    ) {}
}
