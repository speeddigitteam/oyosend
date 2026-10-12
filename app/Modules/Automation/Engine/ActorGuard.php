<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;

/**
 * Decides whether a workflow may use a trigger or action right now.
 *
 * A workflow runs as one member (its publisher): every trigger and action is checked against that member's
 * live permissions and the tenant's live entitlements. The same check runs when the workflow is published
 * and again before every step, so revoking a permission or an app stops the workflow at its next step.
 *
 * Actions are not record-scoped (they operate on ids named in the workflow), so a permission that can be
 * narrowed to own / department / branch only counts when the member holds it for `all` records.
 */
final class ActorGuard
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly AccessChecker $access,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @return string|null A stable denial code, or null when the member may proceed.
     */
    public function denial(?TenantMembership $actor, ?string $permission, ?string $module): ?string
    {
        $tenant = $this->context->require();

        if (! $this->entitlements->allows($tenant, 'automation')) {
            return 'automation_not_entitled';
        }

        if ($module !== null && ! $this->entitlements->allows($tenant, $module)) {
            return 'module_not_entitled';
        }

        if ($actor === null) {
            return 'actor_missing';
        }

        if ($actor->status !== MembershipStatus::Active) {
            return 'actor_inactive';
        }

        if ($permission === null) {
            return null;
        }

        // A long-lived worker must not answer from a stale per-process memo.
        $this->access->forgetMemberships();

        $scope = $this->access->scopeFor($actor->user, $permission);

        if ($scope === null) {
            return 'permission_missing';
        }

        $definition = $this->modules->findPermission($permission);

        if ($definition !== null && $definition->scopable && $scope !== AccessScope::All) {
            return 'permission_scope';
        }

        return null;
    }
}
