<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Shared plumbing of the Payroll controllers: the actor, employee lookup through the HRM contract. */
final class PayrollControllerSupport
{
    private const int OPTION_LIMIT = 500;

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly EmployeeDirectory $directory,
        private readonly PayrollSettings $settings,
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

    public function records(): RecordAccess
    {
        return $this->records;
    }

    public function today(): CarbonImmutable
    {
        return $this->settings->today();
    }

    public function currency(): string
    {
        return $this->settings->currency();
    }

    public function directory(): EmployeeDirectory
    {
        return $this->directory;
    }

    /**
     * The employee a form names, read through the directory contract. An unknown id (including an
     * employee of another workspace) is a validation error; an employee outside the actor's scope
     * for `$permission` is a 404.
     */
    public function employee(User $actor, string $employeeId, string $permission): EmployeeSnapshot
    {
        $employee = $this->directory->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => __('The employee was not found.')]);
        }

        abort_unless($this->records->allows($actor, $permission, $this->probe($employee)), 404);

        return $employee;
    }

    /**
     * Employees the actor may pick for `$permission` (their record scope applied), for form lists.
     *
     * @return list<array{id: string, number: string, name: string, department: string|null}>
     */
    public function employeeOptions(User $actor, string $permission): array
    {
        $options = [];

        foreach ($this->directory->list(['status' => ['probation', 'active', 'on_leave', 'suspended']], self::OPTION_LIMIT) as $employee) {
            if ($this->records->allows($actor, $permission, $this->probe($employee))) {
                $options[] = ['id' => $employee->id, 'number' => $employee->number, 'name' => $employee->name, 'department' => $employee->departmentName];
            }
        }

        usort($options, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * Snapshots of employees by id, for decorating lists.
     *
     * @param  list<string>  $ids
     * @return array<string, EmployeeSnapshot>
     */
    public function employees(array $ids): array
    {
        return $this->directory->findMany(array_values(array_unique($ids)));
    }

    /** An unsaved record carrying the employee's placement, so `RecordAccess` can judge scope. */
    private function probe(EmployeeSnapshot $employee): Profile
    {
        return new Profile([
            'employee_id' => $employee->id,
            'owner_id' => $employee->membershipId,
            'department_id' => $employee->departmentId,
            'branch_id' => $employee->branchId,
        ]);
    }
}
