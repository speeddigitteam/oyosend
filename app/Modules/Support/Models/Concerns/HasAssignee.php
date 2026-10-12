<?php

declare(strict_types=1);

namespace App\Modules\Support\Models\Concerns;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record-owner column contract of {@see HasRecordOwner} (`owner_id`,
 * `department_id`, `branch_id`, read by `RecordAccess`) without its "the creator becomes the owner" hook.
 * For a ticket the owner is the assignee: the person who filed it is not its assignee, and an unassigned
 * ticket must stay unassigned.
 *
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property-read TenantMembership|null $owner
 *
 * @phpstan-require-extends Model
 */
trait HasAssignee
{
    /** Assign to a member (or to nobody), taking their department and branch. */
    public function assignTo(?TenantMembership $assignee, ?string $fallbackDepartmentId = null): static
    {
        $this->setAttribute('owner_id', $assignee?->id);
        $this->setAttribute('department_id', $assignee->department_id ?? $fallbackDepartmentId);
        $this->setAttribute('branch_id', $assignee?->branch_id);

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
