<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\Gender;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\HrmPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Shared plumbing of the HRM controllers: the actor, their employee record and form option lists. */
final class HrmControllerSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly EmployeeResolver $resolver,
        private readonly HrmSettings $settings,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /** The actor's own employee record, or null. */
    public function ownEmployee(User $actor): ?Employee
    {
        return $this->resolver->forUser($actor);
    }

    public function today(): CarbonImmutable
    {
        return $this->settings->today();
    }

    /** A valid `Y-m-d` from a query string, else the fallback. */
    public function date(Request $request, string $key, string $fallback): string
    {
        $value = $request->query($key);

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 && strtotime($value) !== false) {
            return $value;
        }

        return $fallback;
    }

    /** A valid `Y-m` month from a query string (first day), else the current month. */
    public function month(Request $request, string $key = 'month'): CarbonImmutable
    {
        $value = $request->query($key);

        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) === 1) {
            return CarbonImmutable::parse($value.'-01', 'UTC');
        }

        return $this->today()->startOfMonth();
    }

    /**
     * The people an HR form can pick from. With an actor, the manager and member pickers are narrowed to
     * that person's record scope, and a member's email is shown only with workspace-wide personal-data access.
     *
     * @return array<string, mixed>
     */
    public function employeeFormOptions(?Employee $except = null, ?User $actor = null): array
    {
        $managerQuery = Employee::query()
            ->where('status', '!=', EmployeeStatus::Terminated)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id));

        if ($actor !== null) {
            $managerQuery = $this->records->constrain($managerQuery, $actor, HrmPermissions::EMPLOYEES_VIEW);

            if ($except !== null && $except->manager_id !== null) {
                // Keep the saved manager selectable so an edit does not silently clear it.
                $managerQuery = Employee::query()->where('status', '!=', EmployeeStatus::Terminated)
                    ->whereKeyNot($except->id)
                    ->where(fn ($inner) => $inner->whereKey($except->manager_id)
                        ->orWhereIn('id', $this->records->constrain(Employee::query(), $actor, HrmPermissions::EMPLOYEES_VIEW)->select('id')));
            }
        }

        $managers = $managerQuery
            ->orderBy('first_name')->orderBy('last_name')
            ->limit(500)
            ->get(['id', 'employee_number', 'first_name', 'last_name'])
            ->map(static fn (Employee $employee): array => ['id' => $employee->id, 'number' => $employee->employee_number, 'name' => $employee->fullName()])
            ->all();

        $linked = Employee::query()->whereNotNull('owner_id')->pluck('owner_id')->all();
        $memberQuery = TenantMembership::query()
            ->with('user:id,name,email')
            ->where('status', MembershipStatus::Active)
            ->when($except?->owner_id !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNotIn('id', $linked)->orWhere('id', $except?->owner_id)))
            ->when($except?->owner_id === null, fn ($query) => $query->whereNotIn('id', $linked));
        $showEmail = true;

        if ($actor !== null) {
            $showEmail = $this->access->scopeFor($actor, HrmPermissions::PERSONAL_VIEW) === AccessScope::All;
            $scope = $this->access->scopeFor($actor, HrmPermissions::EMPLOYEES_VIEW);
            $own = $this->access->membership($actor);

            if ($scope === null || $own === null) {
                $memberQuery->whereRaw('1 = 0');
            } elseif ($scope !== AccessScope::All) {
                $memberQuery->where(function ($match) use ($scope, $own, $except): void {
                    $match->where('id', $own->id);

                    if ($except?->owner_id !== null) {
                        $match->orWhere('id', $except->owner_id);
                    }

                    if ($scope->covers(AccessScope::Department) && $own->department_id !== null) {
                        $match->orWhere('department_id', $own->department_id);
                    }

                    if ($scope->covers(AccessScope::Branch) && $own->branch_id !== null) {
                        $match->orWhere('branch_id', $own->branch_id);
                    }
                });
            }
        }

        $members = $memberQuery
            ->limit(500)
            ->get()
            ->map(static fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name, 'email' => $showEmail ? $membership->user->email : ''])
            ->sortBy('name')->values()->all();

        return [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->all(),
            'designations' => Designation::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(static fn (Designation $designation): array => ['id' => $designation->id, 'name' => $designation->name])->all(),
            'managers' => $managers,
            'members' => $members,
            'employmentTypes' => array_map(static fn (EmploymentType $type): string => $type->value, EmploymentType::cases()),
            'genders' => array_map(static fn (Gender $gender): string => $gender->value, Gender::cases()),
        ];
    }

    public function records(): RecordAccess
    {
        return $this->records;
    }
}
