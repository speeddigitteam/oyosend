<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;

/**
 * Who a signed-in person is in HRM, and what they are to somebody else's record.
 *
 * The link is the employee's workspace membership (`hrm_employees.owner_id`); a person
 * without an employee record simply has none, and every question then answers no.
 */
final class EmployeeResolver
{
    /** @var array<string, Employee|null> */
    private array $own = [];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
    ) {}

    /** The signed-in person's own employee record in the active workspace. */
    public function forUser(User $user): ?Employee
    {
        $membership = $this->access->membership($user);

        if ($membership === null) {
            return null;
        }

        $key = $membership->tenant_id.':'.$membership->id;

        if (! array_key_exists($key, $this->own)) {
            $this->own[$key] = Employee::query()->where('owner_id', $membership->id)->first();
        }

        return $this->own[$key];
    }

    public function forget(): void
    {
        $this->own = [];
    }

    /** Whether the person is the (current, not terminated) reporting manager of the employee. */
    public function isManagerOf(User $user, Employee $target): bool
    {
        $own = $this->forUser($user);

        return $own !== null
            && $target->manager_id === $own->id
            && $own->status !== EmployeeStatus::Terminated;
    }

    public function isSelf(User $user, Employee $target): bool
    {
        $own = $this->forUser($user);

        return $own !== null && $own->id === $target->id;
    }

    /**
     * May the person act on a record of this employee with a scoped permission: either the
     * record scope covers it, or they are the employee's reporting manager and hold the
     * permission at any scope.
     */
    public function mayActOn(User $user, string $permission, Employee $target, bool $managerCounts = false): bool
    {
        if ($this->records->allows($user, $permission, $target)) {
            return true;
        }

        return $managerCounts && $this->access->allows($user, $permission) && $this->isManagerOf($user, $target);
    }
}
