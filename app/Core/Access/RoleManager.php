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
     */
    public function create(User $actor, string $name, ?string $description, array $permissions): Role
    {
        return DB::transaction(function () use ($actor, $name, $description, $permissions): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'tenant_id' => $this->context->requireId(),
                'name' => $name,
                'guard_name' => 'web',
                'description' => $description,
                'is_system' => false,
            ]);

            $this->syncPermissions($actor, $role, $permissions);

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    public function update(User $actor, Role $role, string $name, ?string $description, array $permissions): Role
    {
        $this->guardEditable($role);

        return DB::transaction(function () use ($actor, $role, $name, $description, $permissions): Role {
            // System roles keep their name: other code refers to them by it.
            $role->fill([
                'name' => $role->is_system ? $role->name : $name,
                'description' => $description,
            ])->save();

            $this->syncPermissions($actor, $role, $permissions);

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

        foreach ($this->grantedNames($role) as $permission) {
            if (! isset($held[$permission])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace a role's grants. Used by provisioning too, where there is no actor.
     *
     * @param  list<string>  $permissions
     */
    public function syncPermissions(?User $actor, Role $role, array $permissions): void
    {
        $this->guardOwnedByTenant($role);

        $requested = array_values(array_unique($permissions));
        sort($requested);
        $allowed = $this->assignablePermissionNames();

        if (array_diff($requested, $allowed) !== []) {
            throw ValidationException::withMessages([
                'permissions' => __('One or more selected permissions are not available to this workspace.'),
            ]);
        }

        if ($actor !== null) {
            $held = $this->access->effectivePermissions($actor);
            $current = $this->grantedNames($role);

            // Adding and removing both require holding the permission, so a
            // role editor can neither escalate nor strip what is above them.
            $touched = [...array_diff($requested, $current), ...array_diff($current, $requested)];

            foreach ($touched as $permission) {
                if (! isset($held[$permission])) {
                    throw ValidationException::withMessages([
                        'permissions' => __('You can only grant or remove permissions that you hold yourself.'),
                    ]);
                }
            }
        }

        $ids = Permission::query()
            ->whereIn('name', $requested)
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($ids->count() !== count($requested)) {
            throw new RuntimeException('The permission catalogue is out of date. Run `php artisan permissions:sync`.');
        }

        $before = $this->grantedNames($role);

        $role->permissions()->sync(
            $ids->mapWithKeys(static fn (int $id): array => [$id => ['scope' => AccessScope::All->value]])->all(),
        );

        $this->flush();

        if ($before !== $requested) {
            $this->audit->record('role.permissions_changed', $role, [
                'added' => array_values(array_diff($requested, $before)),
                'removed' => array_values(array_diff($before, $requested)),
            ]);
        }
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
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->getKey())
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
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
