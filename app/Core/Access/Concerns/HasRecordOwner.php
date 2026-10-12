<?php

declare(strict_types=1);

namespace App\Core\Access\Concerns;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A record that belongs to a member of the workspace, so that permissions
 * granted with the `own`, `department` or `branch` scope can be narrowed to
 * it ({@see RecordAccess}).
 *
 * The table needs nullable `owner_id` (a membership), `department_id` and
 * `branch_id`. Department and branch are copied from the owner whenever the
 * owner is set: a record stays with the team it was made in when its owner
 * later moves to another one.
 *
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property-read TenantMembership|null $owner
 *
 * @phpstan-require-extends Model
 */
trait HasRecordOwner
{
    /** Set by {@see keepUnowned()}: the record is workspace-, department- or branch-wide and must not default to its creator. */
    protected bool $keepsUnowned = false;

    public static function bootHasRecordOwner(): void
    {
        // A record made by a signed-in member is theirs unless it was given to someone.
        static::creating(function (Model $model): void {
            if ($model->getAttribute('owner_id') !== null
                || (property_exists($model, 'keepsUnowned') && $model->keepsUnowned === true)) {
                return;
            }

            $user = Auth::guard('web')->user();
            $membership = $user instanceof User ? app(AccessChecker::class)->membership($user) : null;

            if ($membership !== null) {
                $model->setAttribute('owner_id', $membership->id);
                $model->setAttribute('department_id', $membership->department_id);
                $model->setAttribute('branch_id', $membership->branch_id);
            }
        });
    }

    /**
     * Hand the record to a member (or to nobody), taking their department and branch.
     */
    public function assignOwner(?TenantMembership $owner): static
    {
        $this->setAttribute('owner_id', $owner?->id);
        $this->setAttribute('department_id', $owner?->department_id);
        $this->setAttribute('branch_id', $owner?->branch_id);

        return $this;
    }

    /**
     * Stop the creating hook from handing this record to the signed-in member. Call it before the first save,
     * for a record that deliberately belongs to a department, a branch or the whole workspace.
     */
    public function keepUnowned(): static
    {
        $this->keepsUnowned = true;

        return $this;
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
