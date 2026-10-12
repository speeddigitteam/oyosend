<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveType;

/** Picks the policy of a leave type that applies to an employee. */
final class LeavePolicies
{
    /**
     * An active policy naming the employee's employment type wins over a general one;
     * ties go to the first by name.
     */
    public function forEmployee(Employee $employee, LeaveType $type): ?LeavePolicy
    {
        $policies = LeavePolicy::query()
            ->where('leave_type_id', $type->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $specific = $policies->first(static fn (LeavePolicy $policy): bool => $policy->employment_types !== null
            && $policy->employment_types !== []
            && in_array($employee->employment_type->value, $policy->employment_types, true));

        if ($specific !== null) {
            return $specific;
        }

        return $policies->first(static fn (LeavePolicy $policy): bool => $policy->employment_types === null || $policy->employment_types === []);
    }
}
