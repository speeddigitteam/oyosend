<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models\Concerns;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Workforce\Data\EmployeeSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner, department and branch columns that scoped permissions (`own`, `department`, `branch`) compare
 * through `RecordAccess`. Unlike `HasRecordOwner` the signed-in creator never becomes the owner by
 * accident: placement is always set explicitly, either from the employee a record is about
 * ({@see placeForEmployee()}) or from the parent record it belongs to ({@see copyPlacement()}).
 *
 * For employee records the owner is the employee's workspace membership (null while the employee has
 * no account). The employee is read through the employee directory; nothing here knows HRM models.
 *
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 *
 * @phpstan-require-extends Model
 */
trait Placed
{
    public function placeForEmployee(EmployeeSnapshot $employee): static
    {
        $this->setAttribute('employee_id', $employee->id);
        $this->setAttribute('owner_id', $employee->membershipId);
        $this->setAttribute('department_id', $employee->departmentId);
        $this->setAttribute('branch_id', $employee->branchId);

        return $this;
    }

    public function copyPlacement(Model $parent): static
    {
        $this->setAttribute('owner_id', $parent->getAttribute('owner_id'));
        $this->setAttribute('department_id', $parent->getAttribute('department_id'));
        $this->setAttribute('branch_id', $parent->getAttribute('branch_id'));

        return $this;
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
