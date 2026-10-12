<?php

declare(strict_types=1);

namespace App\Core\Workforce\Data;

/**
 * A read-only picture of one employee, handed to other modules (Payroll, Talent,
 * Projects) so they never import HRM models. Dates are local `Y-m-d` strings,
 * money is integer minor units. It carries no personal or sensitive field
 * (no date of birth, national id, address or documents).
 */
final readonly class EmployeeSnapshot
{
    public function __construct(
        public string $id,
        public string $number,
        public string $firstName,
        public ?string $lastName,
        public string $name,
        /** The linked workspace membership (the team-work identity), or null. */
        public ?string $membershipId,
        public ?string $workEmail,
        public ?string $departmentId,
        public ?string $departmentName,
        public ?string $branchId,
        public ?string $designationId,
        public ?string $designationName,
        /** `full_time`, `part_time`, `contract`, `intern` or `consultant`. */
        public string $employmentType,
        /** `probation`, `active`, `on_leave`, `suspended` or `terminated`. */
        public string $status,
        public ?string $managerId,
        public string $joinedOn,
        public ?string $terminatedOn,
        public ?string $lastWorkingOn,
        /** Hourly internal cost in minor units of `costCurrency` (a costing hook, not a salary). */
        public ?int $costRateAmount,
        public ?string $costCurrency,
        /** End of the probation period (`Y-m-d`), when one is set. Added for Talent's probation tracking. */
        public ?string $probationEndsOn = null,
        /** The date probation was confirmed (`Y-m-d`), when it was. */
        public ?string $confirmedOn = null,
    ) {}

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'name' => $this->name,
            'membership_id' => $this->membershipId,
            'work_email' => $this->workEmail,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'branch_id' => $this->branchId,
            'designation_id' => $this->designationId,
            'designation_name' => $this->designationName,
            'employment_type' => $this->employmentType,
            'status' => $this->status,
            'manager_id' => $this->managerId,
            'joined_on' => $this->joinedOn,
            'terminated_on' => $this->terminatedOn,
            'last_working_on' => $this->lastWorkingOn,
            'cost_rate_amount' => $this->costRateAmount,
            'cost_currency' => $this->costCurrency,
            'probation_ends_on' => $this->probationEndsOn,
            'confirmed_on' => $this->confirmedOn,
        ];
    }
}
