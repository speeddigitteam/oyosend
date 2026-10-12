<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salary revisions (immutable history) and the payment account of an employee.
 *
 * A raise, a correction or a change of structure is always a new revision with a later
 * `effective_from`. A run uses the revision in force on the last day of its period.
 */
final class SalaryManager
{
    /** Largest basic salary (monthly, minor units). */
    public const int MAX_BASIC = 1_000_000_000_000;

    public function __construct(
        private readonly Profiles $profiles,
        private readonly PayrollSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function assign(
        EmployeeSnapshot $employee,
        SalaryStructure $structure,
        int $basicAmount,
        string $effectiveFrom,
        ?string $reason,
        ?TenantMembership $actor = null,
    ): SalaryAssignment {
        if ($basicAmount < 0 || $basicAmount > self::MAX_BASIC) {
            throw ValidationException::withMessages(['basic_amount' => __('The amount is outside the supported range.')]);
        }

        if (! $structure->is_active) {
            throw ValidationException::withMessages(['structure_id' => __('This salary structure is not active.')]);
        }

        return DB::transaction(function () use ($employee, $structure, $basicAmount, $effectiveFrom, $reason, $actor): SalaryAssignment {
            $profile = $this->profiles->ensure($employee);
            // Serialize concurrent revisions of one person.
            Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $last = SalaryAssignment::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->first();

            if ($last !== null && $effectiveFrom <= $last->effective_from->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => __('A new salary revision must take effect after the current one (:date).', ['date' => $last->effective_from->toDateString()]),
                ]);
            }

            $assignment = SalaryAssignment::query()->create([
                'employee_id' => $employee->id,
                'profile_id' => $profile->id,
                'structure_id' => $structure->id,
                'revision' => ($last->revision ?? 0) + 1,
                'basic_amount' => $basicAmount,
                'currency' => $this->settings->currency(),
                'effective_from' => $effectiveFrom,
                'reason' => $reason,
                'actor_id' => $actor?->id,
            ]);

            $this->audit->record('payroll.salary.revised', $profile, [
                'employee_id' => $employee->id,
                'revision' => $assignment->revision,
                'structure_id' => $structure->id,
                'effective_from' => $effectiveFrom,
            ], subjectLabel: $employee->name);

            return $assignment;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateAccount(Profile $profile, array $attributes): Profile
    {
        return DB::transaction(function () use ($profile, $attributes): Profile {
            $profile->fill([
                'payment_method' => $attributes['payment_method'] ?? null,
                'account_holder' => $attributes['account_holder'] ?? null,
                'bank_name' => $attributes['bank_name'] ?? null,
                'bank_branch' => $attributes['bank_branch'] ?? null,
                'routing_number' => $attributes['routing_number'] ?? null,
            ]);

            // A blank number keeps the stored one; an explicit value replaces it.
            if (array_key_exists('account_number', $attributes) && $attributes['account_number'] !== null && $attributes['account_number'] !== '') {
                $profile->account_number = (string) $attributes['account_number'];
            }

            if (($attributes['clear_account_number'] ?? false) === true) {
                $profile->account_number = null;
            }

            $profile->save();
            $this->audit->record('payroll.account.updated', $profile, ['employee_id' => $profile->employee_id]);

            return $profile;
        });
    }

    /** The revision in force on a date (the latest with `effective_from <= $date`), or null. */
    public function inForce(string $employeeId, string $date): ?SalaryAssignment
    {
        return SalaryAssignment::query()
            ->with('structure.components')
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }
}
