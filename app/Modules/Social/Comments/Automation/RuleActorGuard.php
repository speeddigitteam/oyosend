<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;

/**
 * A comment rule acts as the member who last saved it. There is no system identity: before every automatic
 * action the member's live permission is checked, so removing the member or narrowing their role stops the rule
 * at once (ADR 0017's model, applied to Social; ADR 0025).
 *
 * Rules are not record-scoped, so a permission that can be narrowed counts only when held for `all` records.
 */
final class RuleActorGuard
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly AccessChecker $access,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @return string|null A stable denial code, or null when the member may proceed. */
    public function denial(?TenantMembership $actor, string $permission): ?string
    {
        if (! $this->entitlements->allows($this->context->require(), 'social')) {
            return 'module_not_entitled';
        }

        if ($actor === null) {
            return 'actor_missing';
        }

        if ($actor->status !== MembershipStatus::Active) {
            return 'actor_inactive';
        }

        // A long-lived worker must not answer from a stale per-process memo.
        $this->access->forgetMemberships();
        $scope = $this->access->scopeFor($actor->user, $permission);

        if ($scope === null) {
            return 'permission_missing';
        }

        $definition = $this->modules->findPermission($permission);

        return $definition !== null && $definition->scopable && $scope !== AccessScope::All ? 'permission_scope' : null;
    }
}
