<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\HistoryEvent;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Enums\TerminationType;
use App\Modules\Hrm\Events\EmployeeCreated;
use App\Modules\Hrm\Events\EmployeeTerminated;
use App\Modules\Hrm\Events\EmployeeUpdated;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Models\ShiftAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes employees. Every change that matters (placement, manager,
 * employment type, link, status) is appended to the immutable employment history
 * in the same transaction, audited, and announced after commit.
 */
final class EmployeeManager
{
    public const string LIMIT = 'hrm.employees';

    /** Fields HR may edit on an existing employee (status and number have their own paths). */
    private const array EDITABLE = [
        'first_name', 'last_name', 'work_email', 'personal_email', 'phone', 'date_of_birth', 'gender', 'national_id',
        'address', 'city', 'country', 'designation_id', 'department_id', 'branch_id', 'manager_id',
        'employment_type', 'probation_ends_on', 'cost_rate_amount', 'cost_currency', 'notes', 'membership_id',
    ];

    /** Edited fields that are written to the employment history. */
    private const array TRACKED = [
        'designation_id', 'department_id', 'branch_id', 'manager_id', 'employment_type', 'owner_id',
        'probation_ends_on', 'cost_rate_amount', 'cost_currency',
    ];

    private const int MAX_MANAGER_DEPTH = 50;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly UsageLimits $limits,
        private readonly EmployeeNumbers $numbers,
        private readonly EmploymentHistoryRecorder $history,
        private readonly HrmSettings $settings,
        private readonly EmployeeResolver $resolver,
        private readonly LeaveAccruals $accruals,
        private readonly LeaveManager $leave,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $actor = null): Employee
    {
        $attributes = $this->prepare($attributes);
        $tenantId = $this->context->requireId();

        $employee = DB::transaction(function () use ($attributes, $actor, $tenantId): Employee {
            // Serialize the plan-limit check with the insert so concurrent creates cannot both fit.
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->limits->ensure(self::LIMIT, field: 'first_name');
            $this->guardReferences($attributes, null);

            $status = $attributes['status'] ?? EmployeeStatus::Probation->value;
            $status = EmployeeStatus::from((string) $status);

            if (! in_array($status, [EmployeeStatus::Probation, EmployeeStatus::Active], true)) {
                throw ValidationException::withMessages(['status' => __('A new employee starts on probation or active.')]);
            }

            $owner = $this->membership($attributes['membership_id'] ?? null);
            $attributes['employee_number'] = $this->numbers->next();
            $attributes['status'] = $status;
            $attributes['owner_id'] = $owner?->id;
            $attributes['status_changed_on'] = $attributes['joined_on'];
            unset($attributes['membership_id']);

            if ($owner !== null) {
                $attributes['department_id'] ??= $owner->department_id;
                $attributes['branch_id'] ??= $owner->branch_id;
            }

            $employee = new Employee($attributes);
            $employee->save();

            $this->history->record($employee, HistoryEvent::Joined, $employee->joined_on->toDateString(), [
                'status' => ['from' => null, 'to' => $status->value],
                'employment_type' => ['from' => null, 'to' => $employee->employment_type->value],
                'department_id' => ['from' => null, 'to' => $employee->department_id],
                'designation_id' => ['from' => null, 'to' => $employee->designation_id],
                'manager_id' => ['from' => null, 'to' => $employee->manager_id],
            ], null, $actor);
            $this->audit->record('hrm.employee.joined', $employee, ['number' => $employee->employee_number]);

            EmployeeCreated::dispatch($this->snapshot($employee) + ['status' => $employee->status->value]);

            return $employee;
        });

        $this->resolver->forget();
        // Leave accrues from the join date: grant what is already due so the new person has a balance.
        $this->accruals->accrueEmployee($employee);

        return $employee->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(Employee $employee, array $attributes, ?TenantMembership $actor = null): Employee
    {
        $attributes = $this->prepare(array_intersect_key($attributes, array_flip(self::EDITABLE)));

        $updated = DB::transaction(function () use ($employee, $attributes, $actor): Employee {
            /** @var Employee $locked */
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->guardReferences($attributes, $locked);

            if (array_key_exists('membership_id', $attributes)) {
                $owner = $this->membership($attributes['membership_id']);
                $attributes['owner_id'] = $owner?->id;
                unset($attributes['membership_id']);
            }

            $before = $locked->getAttributes();
            $locked->fill($attributes);
            $dirty = array_keys($locked->getDirty());

            if ($dirty === []) {
                return $locked;
            }

            $locked->save();

            $tracked = [];

            foreach (array_intersect($dirty, self::TRACKED) as $field) {
                $tracked[$field] = ['from' => $this->plain($before[$field] ?? null), 'to' => $this->plain($locked->getAttribute($field))];
            }

            if ($tracked !== []) {
                $event = array_keys($tracked) === ['owner_id'] ? HistoryEvent::MembershipLinked : HistoryEvent::Updated;
                $this->history->record($locked, $event, $this->settings->today()->toDateString(), $tracked, null, $actor);
            }

            $this->propagatePlacement($locked, $dirty);

            $changed = array_values(array_diff($dirty, ['national_id', 'updated_at']));
            EmployeeUpdated::dispatch(['employee_id' => $locked->id, 'number' => $locked->employee_number, 'membership_id' => $locked->owner_id, 'changed' => implode(',', $changed)]);

            return $locked;
        });

        $this->resolver->forget();

        return $updated->refresh();
    }

    /**
     * Move an employee through the lifecycle.
     *
     * @param  array{effective_on?: string|null, reason?: string|null, termination_type?: string|null, last_working_on?: string|null}  $data
     */
    public function changeStatus(Employee $employee, EmployeeStatus $to, array $data, ?TenantMembership $actor = null): Employee
    {
        $effective = (string) ($data['effective_on'] ?? $this->settings->today()->toDateString());
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $reason = $reason === '' ? null : $reason;
        $tenantId = $this->context->requireId();

        $result = DB::transaction(function () use ($employee, $to, $data, $effective, $reason, $actor, $tenantId): Employee {
            /** @var Employee $locked */
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($to, $from->transitions(), true)) {
                throw ValidationException::withMessages(['status' => __('That status change is not allowed from the current status.')]);
            }

            if ($effective < $locked->joined_on->toDateString()) {
                throw ValidationException::withMessages(['effective_on' => __('The date cannot be before the joining date.')]);
            }

            if (in_array($to, [EmployeeStatus::Suspended, EmployeeStatus::Terminated], true) && $reason === null) {
                throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
            }

            if ($from === EmployeeStatus::Terminated) {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $this->limits->ensure(self::LIMIT, field: 'status');
            }

            $changes = ['status' => ['from' => $from->value, 'to' => $to->value]];
            $locked->status = $to;
            $locked->setAttribute('status_changed_on', $effective);
            $locked->status_reason = $reason;
            $event = HistoryEvent::StatusChanged;

            if ($to === EmployeeStatus::Active && $from === EmployeeStatus::Probation) {
                $locked->setAttribute('confirmed_on', $effective);
                $changes['confirmed_on'] = ['from' => null, 'to' => $effective];
            }

            if ($to === EmployeeStatus::Terminated) {
                $type = TerminationType::tryFrom((string) ($data['termination_type'] ?? ''));

                if ($type === null) {
                    throw ValidationException::withMessages(['termination_type' => __('Choose how the employment ended.')]);
                }

                $lastWorking = (string) ($data['last_working_on'] ?? $effective);

                if ($lastWorking < $locked->joined_on->toDateString()) {
                    throw ValidationException::withMessages(['last_working_on' => __('The last working day cannot be before the joining date.')]);
                }

                $locked->setAttribute('terminated_on', $effective);
                $locked->setAttribute('last_working_on', $lastWorking);
                $locked->termination_type = $type;
                $locked->termination_reason = $reason;
                $changes['terminated_on'] = ['from' => null, 'to' => $effective];
                $changes['last_working_on'] = ['from' => null, 'to' => $lastWorking];
                $changes['termination_type'] = ['from' => null, 'to' => $type->value];
                $event = HistoryEvent::Terminated;
            }

            if ($from === EmployeeStatus::Terminated) {
                $locked->setAttribute('terminated_on', null);
                $locked->setAttribute('last_working_on', null);
                $locked->termination_type = null;
                $locked->termination_reason = null;
                $changes['terminated_on'] = ['from' => $employee->terminated_on?->toDateString(), 'to' => null];
                $event = HistoryEvent::Reinstated;
            }

            $locked->save();
            $this->history->record($locked, $event, $effective, $changes, $reason, $actor);
            $this->audit->record('hrm.employee.status_changed', $locked, ['from' => $from->value, 'to' => $to->value, 'effective_on' => $effective, 'reason' => $reason]);

            if ($to === EmployeeStatus::Terminated) {
                $this->afterTermination($locked, $actor);
                EmployeeTerminated::dispatch([
                    'employee_id' => $locked->id,
                    'number' => $locked->employee_number,
                    'name' => $locked->fullName(),
                    'membership_id' => $locked->owner_id,
                    'termination_type' => $locked->termination_type?->value,
                    'terminated_on' => $effective,
                    'last_working_on' => $locked->last_working_on?->toDateString(),
                ]);
            } else {
                EmployeeUpdated::dispatch(['employee_id' => $locked->id, 'number' => $locked->employee_number, 'membership_id' => $locked->owner_id, 'changed' => 'status', 'status' => $to->value]);
            }

            return $locked;
        });

        $this->resolver->forget();

        return $result->refresh();
    }

    /** The number of employees counted against the plan limit. */
    public function countedEmployees(): int
    {
        return Employee::query()->where('status', '!=', EmployeeStatus::Terminated)->count();
    }

    /**
     * Whether `$candidate` may become the manager of `$employee` without making a cycle.
     */
    public function managerWouldCycle(string $employeeId, ?string $candidateId): bool
    {
        $cursor = $candidateId;
        $seen = 0;

        while ($cursor !== null) {
            if ($cursor === $employeeId) {
                return true;
            }

            if (++$seen > self::MAX_MANAGER_DEPTH) {
                return true;
            }

            $cursor = Employee::query()->whereKey($cursor)->value('manager_id');
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        foreach (['first_name', 'last_name', 'work_email', 'personal_email', 'phone', 'city', 'address', 'notes'] as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]) === '' ? null : trim($attributes[$key]);
            }
        }

        if (array_key_exists('first_name', $attributes) && ($attributes['first_name'] === null || $attributes['first_name'] === '')) {
            throw ValidationException::withMessages(['first_name' => __('The first name is required.')]);
        }

        foreach (['cost_currency', 'country'] as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = strtoupper($attributes[$key]);
            }
        }

        if (($attributes['cost_rate_amount'] ?? null) !== null && ! is_int($attributes['cost_rate_amount'])) {
            $attributes['cost_rate_amount'] = (int) $attributes['cost_rate_amount'];
        }

        if (($attributes['cost_rate_amount'] ?? null) !== null && empty($attributes['cost_currency'])) {
            $attributes['cost_currency'] = $this->context->require()->currency;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    private function guardReferences(array $attributes, ?Employee $current): void
    {
        $designationId = $attributes['designation_id'] ?? null;

        if ($designationId !== null && $designationId !== $current?->designation_id) {
            $designation = Designation::query()->find($designationId);

            if ($designation === null || ! $designation->is_active) {
                throw ValidationException::withMessages(['designation_id' => __('The selected designation is not available.')]);
            }
        }

        if (($attributes['department_id'] ?? null) !== null && Department::query()->whereKey($attributes['department_id'])->doesntExist()) {
            throw ValidationException::withMessages(['department_id' => __('The selected department is not available.')]);
        }

        if (($attributes['branch_id'] ?? null) !== null && Branch::query()->whereKey($attributes['branch_id'])->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => __('The selected branch is not available.')]);
        }

        if (array_key_exists('manager_id', $attributes) && $attributes['manager_id'] !== null) {
            $manager = Employee::query()->find($attributes['manager_id']);

            if ($manager === null || $manager->status === EmployeeStatus::Terminated) {
                throw ValidationException::withMessages(['manager_id' => __('The selected manager is not available.')]);
            }

            if ($current !== null && $this->managerWouldCycle($current->id, $manager->id)) {
                throw ValidationException::withMessages(['manager_id' => __('That manager would create a reporting loop.')]);
            }
        }

        if (array_key_exists('membership_id', $attributes) && $attributes['membership_id'] !== null) {
            $taken = Employee::query()->where('owner_id', $attributes['membership_id'])
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current?->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['membership_id' => __('That workspace member is already linked to another employee.')]);
            }
        }
    }

    private function membership(mixed $id): ?TenantMembership
    {
        if ($id === null || $id === '') {
            return null;
        }

        $membership = TenantMembership::query()->where('status', MembershipStatus::Active)->find((string) $id);

        if ($membership === null) {
            throw ValidationException::withMessages(['membership_id' => __('The selected workspace member is not available.')]);
        }

        return $membership;
    }

    /** @param list<string> $dirty */
    private function propagatePlacement(Employee $employee, array $dirty): void
    {
        $ownerChanged = in_array('owner_id', $dirty, true);
        $teamChanged = in_array('department_id', $dirty, true) || in_array('branch_id', $dirty, true);

        if (! $ownerChanged && ! $teamChanged) {
            return;
        }

        $values = [];

        if ($ownerChanged) {
            // A person's own history becomes theirs the moment their account is linked.
            foreach ([AttendanceEntry::class, AttendanceDay::class, LeaveRequest::class, Regularization::class, ShiftAssignment::class] as $model) {
                $model::query()->where('employee_id', $employee->id)->update(['owner_id' => $employee->owner_id]);
            }
        }

        if ($teamChanged) {
            $values = ['department_id' => $employee->department_id, 'branch_id' => $employee->branch_id];
            // Open work follows the new team; closed history stays with the team it happened in.
            LeaveRequest::query()->where('employee_id', $employee->id)
                ->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr])->update($values);
            Regularization::query()->where('employee_id', $employee->id)
                ->where('status', RegularizationStatus::Pending)->update($values);
            ShiftAssignment::query()->where('employee_id', $employee->id)->update($values);
        }
    }

    private function afterTermination(Employee $employee, ?TenantMembership $actor): void
    {
        // Direct reports move up to the leaver's own manager (or to nobody) rather than dangling.
        $reports = Employee::query()->where('manager_id', $employee->id)->get();

        foreach ($reports as $report) {
            $report->update(['manager_id' => $employee->manager_id]);
            $this->history->record($report, HistoryEvent::Updated, $this->settings->today()->toDateString(), [
                'manager_id' => ['from' => $employee->id, 'to' => $employee->manager_id],
            ], 'Manager left the company', $actor);
        }

        $this->leave->cancelAfterLastWorkingDay($employee, $actor);
    }

    /** @return array<string, scalar|null> */
    private function snapshot(Employee $employee): array
    {
        return [
            'employee_id' => $employee->id,
            'number' => $employee->employee_number,
            'name' => $employee->fullName(),
            'membership_id' => $employee->owner_id,
            'department_id' => $employee->department_id,
            'branch_id' => $employee->branch_id,
            'designation_id' => $employee->designation_id,
            'manager_id' => $employee->manager_id,
            'employment_type' => $employee->employment_type->value,
            'joined_on' => $employee->joined_on->toDateString(),
        ];
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
