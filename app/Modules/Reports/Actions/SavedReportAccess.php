<?php

declare(strict_types=1);

namespace App\Modules\Reports\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\Reports\Enums\Visibility;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may open and who may change a saved report. Opening is not a data grant: a report shared
 * with a role or department is only a stored setup, and the person who opens it runs the report
 * with their own permission and record scope.
 */
final class SavedReportAccess
{
    public const string MANAGE = 'reports.saved.manage';

    public const string MANAGE_ALL = 'reports.saved.manage_all';

    /** @var array<string, list<string>> */
    private array $roles = [];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly TenantContext $context,
    ) {}

    /**
     * Saved reports the viewer may open: their own, those shared with one of their roles or with
     * their department, and every one for someone who manages all of them.
     *
     * @return Builder<SavedReport>
     */
    public function visible(User $viewer): Builder
    {
        $query = SavedReport::query();

        if ($this->access->allows($viewer, self::MANAGE_ALL)) {
            return $query;
        }

        $membership = $this->access->membership($viewer);

        if ($membership === null) {
            return $query->whereRaw('1 = 0');
        }

        $roleIds = $this->roleIds($viewer);

        return $query->where(function (Builder $match) use ($membership, $roleIds): void {
            $match->where('owner_membership_id', $membership->id);

            if ($roleIds !== []) {
                $match->orWhere(fn (Builder $role) => $role->where('visibility', Visibility::Role->value)->whereIn('role_id', $roleIds));
            }

            if ($membership->department_id !== null) {
                $match->orWhere(fn (Builder $department) => $department
                    ->where('visibility', Visibility::Department->value)
                    ->where('department_id', $membership->department_id));
            }
        });
    }

    public function canOpen(User $viewer, SavedReport $saved): bool
    {
        return $this->visible($viewer)->whereKey($saved->getKey())->exists();
    }

    /** The owner may change their own; someone holding `reports.saved.manage_all` may change any. */
    public function canManage(User $viewer, SavedReport $saved): bool
    {
        if (! $this->access->allows($viewer, self::MANAGE)) {
            return false;
        }

        $membership = $this->access->membership($viewer);

        return ($membership !== null && $saved->owner_membership_id === $membership->id)
            || $this->access->allows($viewer, self::MANAGE_ALL);
    }

    /** @return list<string> */
    private function roleIds(User $viewer): array
    {
        $tenantId = $this->context->requireId();
        $key = $tenantId.':'.$viewer->getKey();

        return $this->roles[$key] ??= (new Role)->getConnection()
            ->table('model_has_roles')
            ->where('tenant_id', $tenantId)
            ->where('model_type', $viewer->getMorphClass())
            ->where('model_id', $viewer->getKey())
            ->pluck('role_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }
}
