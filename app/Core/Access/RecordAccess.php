<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Access\Enums\AccessScope;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Narrows record-level access to the scope a permission was granted with.
 *
 * A grant reaches, from the narrowest scope up:
 *
 *  - `own`         records the person owns;
 *  - `department`  those, plus records of their department;
 *  - `branch`      those, plus records of their branch;
 *  - `all`         every record of the workspace.
 *
 * Someone with no department (or branch) gets no more than their own records
 * from that scope. It works on any model using {@see HasRecordOwner}; the
 * route still names the permission with `can:`, this decides which rows.
 */
final class RecordAccess
{
    public function __construct(private readonly AccessChecker $access) {}

    /**
     * Restrict a query to the records the user may reach with the permission.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $permission): Builder
    {
        $scope = $this->access->scopeFor($user, $permission);
        $membership = $this->access->membership($user);

        if ($scope === null || $membership === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($scope === AccessScope::All) {
            return $query;
        }

        $model = $query->getModel();

        return $query->where(function (Builder $match) use ($model, $scope, $membership): void {
            $match->where($model->qualifyColumn('owner_id'), $membership->id);

            if ($scope->covers(AccessScope::Department) && $membership->department_id !== null) {
                $match->orWhere($model->qualifyColumn('department_id'), $membership->department_id);
            }

            if ($scope->covers(AccessScope::Branch) && $membership->branch_id !== null) {
                $match->orWhere($model->qualifyColumn('branch_id'), $membership->branch_id);
            }
        });
    }

    /**
     * Whether the user may act on this one record with the permission.
     */
    public function allows(User $user, string $permission, Model $record): bool
    {
        $scope = $this->access->scopeFor($user, $permission);
        $membership = $this->access->membership($user);

        if ($scope === null || $membership === null) {
            return false;
        }

        if ($scope === AccessScope::All || $record->getAttribute('owner_id') === $membership->id) {
            return true;
        }

        if ($scope->covers(AccessScope::Department)
            && $membership->department_id !== null
            && $record->getAttribute('department_id') === $membership->department_id) {
            return true;
        }

        return $scope->covers(AccessScope::Branch)
            && $membership->branch_id !== null
            && $record->getAttribute('branch_id') === $membership->branch_id;
    }

    /**
     * Abort with 404 unless the user may act on the record: a record outside
     * someone's scope is not disclosed to exist.
     */
    public function authorize(User $user, string $permission, Model $record): void
    {
        abort_unless($this->allows($user, $permission, $record), 404);
    }
}
