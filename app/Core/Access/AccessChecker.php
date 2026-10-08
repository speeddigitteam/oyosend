<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\Enums\AccessScope;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;

/**
 * The one place a module permission is decided.
 *
 * A permission is effective only when all of these hold, in this order:
 *
 *  1. there is an active tenant;
 *  2. the permission is registered by a module;
 *  3. the tenant is entitled to that module;
 *  4. the user is an active member of the tenant;
 *  5. the user is the tenant's owner, or one of their roles grants it.
 *
 * Nothing here trusts the request: tenant, membership and grants all come
 * from the database.
 */
final class AccessChecker
{
    /** @var array<string, TenantMembership|null> per-request memo */
    private array $memberships = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly PermissionResolver $resolver,
    ) {}

    public function allows(User $user, string $permission): bool
    {
        return $this->scopeFor($user, $permission) !== null;
    }

    /**
     * The scope the user holds the permission with, or null when they do not hold it.
     */
    public function scopeFor(User $user, string $permission): ?AccessScope
    {
        $tenant = $this->context->tenant();
        $definition = $this->modules->findPermission($permission);

        if ($tenant === null || $definition === null) {
            return null;
        }

        if (! $this->entitlements->allows($tenant, $definition->module())) {
            return null;
        }

        $membership = $this->membership($user);

        if ($membership === null) {
            return null;
        }

        if ($membership->is_owner) {
            return AccessScope::All;
        }

        return $this->resolver->grantsFor($user)[$permission] ?? null;
    }

    /**
     * Every permission the user effectively holds in the active tenant.
     *
     * @return array<string, AccessScope>
     */
    public function effectivePermissions(User $user): array
    {
        $tenant = $this->context->tenant();
        $membership = $tenant === null ? null : $this->membership($user);

        if ($tenant === null || $membership === null) {
            return [];
        }

        $entitled = $this->entitlements->entitledModules($tenant);
        $grants = $membership->is_owner ? null : $this->resolver->grantsFor($user);
        $effective = [];

        foreach ($this->modules->permissions() as $name => $definition) {
            if (! in_array($definition->module(), $entitled, true)) {
                continue;
            }

            if ($grants === null) {
                $effective[$name] = AccessScope::All;
            } elseif (isset($grants[$name])) {
                $effective[$name] = $grants[$name];
            }
        }

        return $effective;
    }

    /**
     * The user's active membership in the active tenant.
     */
    public function membership(User $user): ?TenantMembership
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            return null;
        }

        $key = $tenantId.':'.$user->getKey();

        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = TenantMembership::query()
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active)
                ->first();
        }

        return $this->memberships[$key];
    }

    public function forgetMemberships(): void
    {
        $this->memberships = [];
    }
}
