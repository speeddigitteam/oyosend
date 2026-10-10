<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Audit\AuditLogger;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates and changes a tenant's roles.
 *
 * Two rules hold for every change, whoever makes it:
 *  - nobody can hand out a permission they do not hold themselves;
 *  - a role can only carry permissions of modules the tenant is entitled to.
 */
final class RoleManager
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly AccessChecker $access,
        private readonly PermissionResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  array<string, AccessScope|string>  $scopes  Scope per permission; anything not named is granted for `all`.
     */
    public function create(User $actor, string $name, ?string $description, array $permissions, array $scopes = []): Role
    {
        return DB::transaction(function () use ($actor, $name, $description, $permissions, $scopes): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'tenant_id' => $this->context->requireId(),
                'name' => $name,
                'guard_name' => 'web',
                'description' => $description,
                'is_system' => false,
            ]);

            $this->syncPermissions($actor, $role, $permissions, $scopes);

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, AccessScope|string>  $scopes
     */
    public function update(User $actor, Role $role, string $name, ?string $description, array $permissions, array $scopes = []): Role
    {
        $this->guardEditable($role);

        return DB::transaction(function () use ($actor, $role, $name, $description, $permissions, $scopes): Role {
            // System roles keep their name: other code refers to them by it.
            $role->fill([
                'name' => $role->is_system ? $role->name : $name,
                'description' => $description,
            ])->save();

            $this->syncPermissions($actor, $role, $permissions, $scopes);

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        $this->guardOwnedByTenant($role);

        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('System roles cannot be deleted.')]);
        }

        $inUse = DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()
            || DB::table('invitations')->where('role_id', $role->getKey())->whereNull('accepted_at')->whereNull('revoked_at')->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'role' => __('This role is still assigned to people or pending invitations. Reassign them first.'),
            ]);
        }

        DB::transaction(function () use ($role): void {
            $role->delete();
            $this->flush();
        });
    }

    /**
     * Whether `$actor` may give this role to someone: they must already hold
     * everything it grants. The Owner role is never assignable.
     */
    public function canAssign(User $actor, Role $role): bool
    {
        if ($role->tenant_id !== $this->context->id() || $role->isOwnerRole()) {
            return false;
        }

        $held = $this->access->effectivePermissions($actor);

        foreach ($this->grants($role) as $permission => $scope) {
            if (! isset($held[$permission]) || ! $held[$permission]->covers($scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace a role's grants. Used by provisioning too, where there is no actor.
     *
     * @param  list<string>  $permissions
     * @param  array<string, AccessScope|string>  $scopes  Scope per permission. Only a scopable
     *                                                     permission can be narrowed; the rest are always `all`.
     */
    public function syncPermissions(?User $actor, Role $role, array $permissions, array $scopes = []): void
    {
        $this->guardOwnedByTenant($role);

        $requested = $this->requestedGrants($permissions, $scopes);
        $allowed = $this->assignablePermissionNames();

        if (array_diff(array_keys($requested), $allowed) !== []) {
            throw ValidationException::withMessages([
                'permissions' => __('One or more selected permissions are not available to this workspace.'),
            ]);
        }

        $current = $this->grants($role);

        if ($actor !== null) {
            $held = $this->access->effectivePermissions($actor);

            // Adding, removing and re-scoping all require holding the
            // permission at least as widely as the grant being touched, so a
            // role editor can neither escalate nor strip what is above them.
            foreach (array_keys($requested + $current) as $permission) {
                $before = $current[$permission] ?? null;
                $after = $requested[$permission] ?? null;

                if ($before === $after) {
                    continue;
                }

                $widest = $before !== null && $after !== null ? $before->widest($after) : ($before ?? $after);

                if (! isset($held[$permission]) || ! $held[$permission]->covers($widest)) {
                    throw ValidationException::withMessages([
                        'permissions' => __('You can only grant or remove permissions that you hold yourself.'),
                    ]);
                }
            }
        }

        $ids = Permission::query()
            ->whereIn('name', array_keys($requested))
            ->where('guard_name', 'web')
            ->pluck('id', 'name');

        if ($ids->count() !== count($requested)) {
            throw new RuntimeException('The permission catalogue is out of date. Run `php artisan permissions:sync`.');
        }

        $pivot = [];

        foreach ($requested as $permission => $scope) {
            $pivot[$ids[$permission]] = ['scope' => $scope->value];
        }

        $role->permissions()->sync($pivot);

        $this->flush();

        if ($current === $requested) {
            return;
        }

        $changes = [
            'added' => array_values(array_diff(array_keys($requested), array_keys($current))),
            'removed' => array_values(array_diff(array_keys($current), array_keys($requested))),
        ];

        // Only grants that are narrower than "everything" are worth spelling out.
        $narrowed = array_filter(
            $requested,
            static fn (AccessScope $scope, string $permission): bool => $scope !== ($current[$permission] ?? AccessScope::All),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($narrowed !== []) {
            $changes['scopes'] = array_map(static fn (AccessScope $scope): string => $scope->value, $narrowed);
        }

        $this->audit->record('role.permissions_changed', $role, $changes);
    }

    /**
     * Permissions a role in this tenant may carry.
     *
     * @return list<string>
     */
    public function assignablePermissionNames(): array
    {
        $entitled = $this->entitlements->entitledModules($this->context->require());
        $names = [];

        foreach ($this->modules->permissions() as $name => $definition) {
            if (in_array($definition->module(), $entitled, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function grantedNames(Role $role): array
    {
        return array_keys($this->grants($role));
    }

    /**
     * What a role grants, and how far each grant reaches.
     *
     * @return array<string, AccessScope> permission name => scope, in name order
     */
    public function grants(Role $role): array
    {
        $grants = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->getKey())
            ->pluck('role_has_permissions.scope', 'permissions.name')
            ->map(static fn (string $scope): AccessScope => AccessScope::tryFrom($scope) ?? AccessScope::Own)
            ->all();

        // Ordered here, not by the database: its collation sorts differently from PHP.
        ksort($grants);

        return $grants;
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, AccessScope|string>  $scopes
     * @return array<string, AccessScope> in name order
     */
    private function requestedGrants(array $permissions, array $scopes): array
    {
        $names = array_values(array_unique($permissions));
        sort($names);

        $grants = [];

        foreach ($names as $name) {
            $scope = $scopes[$name] ?? AccessScope::All;
            $scope = $scope instanceof AccessScope ? $scope : AccessScope::tryFrom($scope);

            if ($scope === null) {
                throw ValidationException::withMessages(['scopes' => __('One of the selected scopes is not valid.')]);
            }

            $grants[$name] = $this->modules->findPermission($name)?->scopable === true ? $scope : AccessScope::All;
        }

        return $grants;
    }

    /**
     * Drop every cached authorization decision of the active tenant.
     */
    public function flush(): void
    {
        $this->resolver->flush();
        $this->access->forgetMemberships();
        $this->registrar->forgetCachedPermissions();
    }

    private function guardEditable(Role $role): void
    {
        $this->guardOwnedByTenant($role);

        if ($role->isOwnerRole()) {
            throw ValidationException::withMessages(['role' => __('The Owner role always has full access and cannot be changed.')]);
        }
    }

    private function guardOwnedByTenant(Role $role): void
    {
        abort_unless($role->tenant_id === $this->context->requireId(), 404);
    }
}
