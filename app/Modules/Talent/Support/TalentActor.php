<?php

declare(strict_types=1);

namespace App\Modules\Talent\Support;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Data\EmployeeSnapshot;
use Illuminate\Http\Request;

/**
 * Who the signed-in person is for Talent: their membership and, through the employee directory
 * (never an HRM model), their employee record. A person without an employee record simply has none
 * and every self-service question answers no.
 */
final class TalentActor
{
    /** @var array<string, EmployeeSnapshot|null> */
    private array $employees = [];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly EmployeeDirectory $directory,
    ) {}

    public function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);

        return $user;
    }

    public function membership(User $user): TenantMembership
    {
        return $this->access->membership($user) ?? abort(403);
    }

    public function employee(User $user): ?EmployeeSnapshot
    {
        $membership = $this->access->membership($user);

        if ($membership === null) {
            return null;
        }

        $key = $membership->tenant_id.':'.$membership->id;

        if (! array_key_exists($key, $this->employees)) {
            $this->employees[$key] = $this->directory->findByMembership($membership->id);
        }

        return $this->employees[$key];
    }

    public function employeeId(User $user): ?string
    {
        return $this->employee($user)?->id;
    }

    public function isEmployee(User $user, string $employeeId): bool
    {
        return $this->employeeId($user) === $employeeId;
    }

    /** Whether the person is the direct reporting manager of the employee. */
    public function isManagerOf(User $user, string $employeeId): bool
    {
        $own = $this->employeeId($user);

        if ($own === null || $own === $employeeId) {
            return false;
        }

        return $this->directory->find($employeeId)?->managerId === $own;
    }

    public function forget(): void
    {
        $this->employees = [];
    }
}
