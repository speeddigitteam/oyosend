<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models\Concerns;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that belongs to one employee and follows that employee's placement so
 * that scoped permissions (`own`, `department`, `branch`) work through
 * {@see RecordAccess}.
 *
 * It deliberately does not use `HasRecordOwner`: that trait makes the signed-in
 * creator the owner, but HR creates records for other people. Here the owner is the
 * employee's linked membership (null while the employee has no account).
 *
 * @property string $employee_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property-read Employee $employee
 * @property-read TenantMembership|null $owner
 *
 * @phpstan-require-extends Model
 */
trait FollowsEmployee
{
    public function placeFor(Employee $employee): static
    {
        $this->setAttribute('employee_id', $employee->id);
        $this->setAttribute('owner_id', $employee->owner_id);
        $this->setAttribute('department_id', $employee->department_id);
        $this->setAttribute('branch_id', $employee->branch_id);

        return $this;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
