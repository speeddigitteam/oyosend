<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Actions\TalentSettings;
use App\Modules\Talent\Support\TalentActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Shared plumbing of the Talent controllers: the actor, record scoping and form option lists. */
final class TalentSupport
{
    public function __construct(
        private readonly TalentActor $actor,
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly TalentSettings $settings,
        private readonly EmployeeDirectory $directory,
    ) {}

    public function user(Request $request): User
    {
        return $this->actor->user($request);
    }

    public function membership(User $user): TenantMembership
    {
        return $this->actor->membership($user);
    }

    public function employee(User $user): ?EmployeeSnapshot
    {
        return $this->actor->employee($user);
    }

    public function employeeId(User $user): ?string
    {
        return $this->actor->employeeId($user);
    }

    public function isManagerOf(User $user, string $employeeId): bool
    {
        return $this->actor->isManagerOf($user, $employeeId);
    }

    public function today(): CarbonImmutable
    {
        return $this->settings->today();
    }

    public function timezone(): string
    {
        return $this->settings->timezone();
    }

    public function can(User $user, string $permission): bool
    {
        return $this->access->allows($user, $permission);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scoped(Builder $query, User $user, string $permission): Builder
    {
        return $this->records->constrain($query, $user, $permission);
    }

    public function allows(User $user, string $permission, Model $record): bool
    {
        return $this->records->allows($user, $permission, $record);
    }

    /** Abort with 404 unless the record is inside the scope the permission was granted with. */
    public function authorize(User $user, string $permission, Model $record): void
    {
        $this->records->authorize($user, $permission, $record);
    }

    /**
     * Whether the record belongs to the person themselves while their scope is narrower than `all`: nobody
     * completes, fails or decides on their own record unless they hold the permission workspace-wide.
     */
    public function isOwnRecordBlocked(User $user, string $permission, string $employeeId): bool
    {
        return $employeeId === $this->employeeId($user) && $this->access->scopeFor($user, $permission) !== AccessScope::All;
    }

    /**
     * @param  list<string|null>  $ids
     * @return array<string, EmployeeSnapshot>
     */
    public function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?string $id): bool => $id !== null && $id !== '')));

        return $ids === [] ? [] : $this->directory->findMany($ids);
    }

    /**
     * Employees a form can pick from (working employees, at most 500).
     *
     * With a user and a scopable permission the list is narrowed to the employees inside the scope the
     * permission was granted with (`own` is only the person's own record); it is empty without the grant.
     *
     * @return list<array{id: string, number: string, name: string, department: string|null}>
     */
    public function employeeOptions(?User $user = null, ?string $permission = null): array
    {
        $statuses = ['probation', 'active', 'on_leave'];

        if ($user === null || $permission === null) {
            return self::options($this->directory->list(['status' => $statuses], 500));
        }

        $scope = $this->access->scopeFor($user, $permission);
        $membership = $this->access->membership($user);

        if ($scope === null || $membership === null) {
            return [];
        }

        if ($scope === AccessScope::All) {
            $employees = $this->directory->list(['status' => $statuses], 500);
        } else {
            $employees = [];
            $own = $this->employee($user);

            if ($own !== null && in_array($own->status, $statuses, true)) {
                $employees[$own->id] = $own;
            }

            if ($scope->covers(AccessScope::Department) && $membership->department_id !== null) {
                foreach ($this->directory->list(['status' => $statuses, 'department_id' => $membership->department_id], 500) as $employee) {
                    $employees[$employee->id] = $employee;
                }
            }

            if ($scope->covers(AccessScope::Branch) && $membership->branch_id !== null) {
                foreach ($this->directory->list(['status' => $statuses, 'branch_id' => $membership->branch_id], 500) as $employee) {
                    $employees[$employee->id] = $employee;
                }
            }

            $employees = array_values($employees);
        }

        return self::options($employees);
    }

    /**
     * @param  list<EmployeeSnapshot>  $employees
     * @return list<array{id: string, number: string, name: string, department: string|null}>
     */
    private static function options(array $employees): array
    {
        return array_map(static fn (EmployeeSnapshot $employee): array => [
            'id' => $employee->id, 'number' => $employee->number, 'name' => $employee->name, 'department' => $employee->departmentName,
        ], $employees);
    }

    /** Whether the employee lies inside the scope the user holds the permission with (same rule as `RecordAccess`). */
    public function employeeInScope(User $user, string $permission, EmployeeSnapshot $employee): bool
    {
        $scope = $this->access->scopeFor($user, $permission);
        $membership = $this->access->membership($user);

        if ($scope === null || $membership === null) {
            return false;
        }

        if ($scope === AccessScope::All || $employee->membershipId === $membership->id || $employee->id === $this->employeeId($user)) {
            return true;
        }

        if ($scope->covers(AccessScope::Department) && $membership->department_id !== null && $employee->departmentId === $membership->department_id) {
            return true;
        }

        return $scope->covers(AccessScope::Branch) && $membership->branch_id !== null && $employee->branchId === $membership->branch_id;
    }

    /**
     * Refuse (422) employee ids a person may not pick: unknown, or outside the scope of the permission.
     *
     * @param  list<string>  $employeeIds
     */
    public function assertEmployeesInScope(User $user, string $permission, array $employeeIds, string $field): void
    {
        $found = $this->people($employeeIds);

        foreach (array_unique($employeeIds) as $id) {
            $employee = $found[$id] ?? null;

            if ($employee === null || ! $this->employeeInScope($user, $permission, $employee)) {
                throw ValidationException::withMessages([$field => __('Choose an employee of this workspace.')]);
            }
        }
    }

    /** @return array{departments: list<array{id: string, name: string}>, branches: list<array{id: string, name: string}>} */
    public function organization(): array
    {
        return [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->all(),
        ];
    }

    /** A valid `Y-m-d` from a query string, else the fallback. */
    public function date(Request $request, string $key, ?string $fallback = null): ?string
    {
        $value = $request->query($key);

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 && strtotime($value) !== false) {
            return $value;
        }

        return $fallback;
    }
}
