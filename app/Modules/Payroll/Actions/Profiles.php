<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Payroll's per-employee record. It copies the employee's placement from HRM (through the
 * `EmployeeDirectory` contract) so scoped permissions work on Payroll's own tables, and keeps it
 * current when HRM announces a change.
 */
final class Profiles
{
    public function __construct(private readonly EmployeeDirectory $directory) {}

    /** The profile of an employee, created on first use, with its placement refreshed. */
    public function ensure(EmployeeSnapshot $employee): Profile
    {
        $placement = [
            'owner_id' => $employee->membershipId,
            'department_id' => $employee->departmentId,
            'branch_id' => $employee->branchId,
        ];
        $profile = Profile::query()->where('employee_id', $employee->id)->first();

        if ($profile === null) {
            try {
                return Profile::query()->create(['employee_id' => $employee->id, ...$placement])->refresh();
            } catch (UniqueConstraintViolationException) {
                $profile = Profile::query()->where('employee_id', $employee->id)->firstOrFail();
            }
        }

        if ($profile->owner_id !== $placement['owner_id']
            || $profile->department_id !== $placement['department_id']
            || $profile->branch_id !== $placement['branch_id']) {
            $profile->forceFill($placement)->save();
        }

        return $profile;
    }

    public function forEmployee(string $employeeId): ?Profile
    {
        return Profile::query()->where('employee_id', $employeeId)->first();
    }

    /**
     * Re-read one employee and bring every Payroll record that follows the person up to date: the
     * account link on payslips (so the `own` scope keeps working), and the placement of the profile
     * and of open items and loans. The money columns of payslips are never touched.
     */
    public function refresh(string $employeeId): void
    {
        $employee = $this->directory->find($employeeId);

        if ($employee === null) {
            return;
        }

        $placement = [
            'owner_id' => $employee->membershipId,
            'department_id' => $employee->departmentId,
            'branch_id' => $employee->branchId,
        ];

        Profile::query()->where('employee_id', $employeeId)->update($placement);
        Item::query()->where('employee_id', $employeeId)->where('status', ItemStatus::Pending)->update($placement);
        Loan::query()->where('employee_id', $employeeId)->whereIn('status', [LoanStatus::Pending, LoanStatus::Active])->update($placement);
        Item::query()->where('employee_id', $employeeId)->where('status', '!=', ItemStatus::Pending)->update(['owner_id' => $employee->membershipId]);
        Loan::query()->where('employee_id', $employeeId)->whereNotIn('status', [LoanStatus::Pending, LoanStatus::Active])->update(['owner_id' => $employee->membershipId]);
        // Bulk update: it skips the model guard on purpose, and only the account link moves.
        Payslip::query()->where('employee_id', $employeeId)->update(['owner_id' => $employee->membershipId]);
    }
}
