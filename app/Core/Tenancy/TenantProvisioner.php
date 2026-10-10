<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Access\DefaultRoles;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Domain;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a workspace in a usable state: the tenant, its subdomain, its
 * system roles and its owner. Used by sign-up, by the Super Admin panel and by
 * the local demo seeder.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly TenantHost $hosts,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Optional locale, timezone, currency, status, trial_ends_at, onboarded.
     */
    public function provision(string $name, string $slug, User $owner, array $attributes = []): Tenant
    {
        $slug = Str::lower(trim($slug));

        if ($this->hosts->isReserved($slug) || preg_match('/^'.TenantHost::LABEL_PATTERN.'$/', $slug) !== 1) {
            throw new InvalidArgumentException("[{$slug}] cannot be used as a workspace address.");
        }

        $status = $attributes['status'] ?? TenantStatus::Active;
        $status = $status instanceof TenantStatus ? $status : TenantStatus::from((string) $status);

        return DB::transaction(function () use ($name, $slug, $owner, $attributes, $status): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                // Built as an active workspace and handed over in the requested
                // state at the end: system roles can only be given permissions
                // while the tenant is entitled to the core module, which a
                // pending or suspended tenant is not.
                'status' => TenantStatus::Active,
                'locale' => $attributes['locale'] ?? config('platform.defaults.locale'),
                'timezone' => $attributes['timezone'] ?? config('platform.defaults.timezone'),
                'currency' => $attributes['currency'] ?? config('platform.defaults.currency'),
                'trial_ends_at' => $attributes['trial_ends_at'] ?? null,
                // Only a workspace someone signed up for goes through first-run setup.
                'onboarded_at' => ($attributes['onboarded'] ?? true) ? Carbon::now() : null,
            ]);

            $tenant->domains()->create([
                'domain' => $slug,
                'type' => Domain::TYPE_SUBDOMAIN,
                'is_primary' => true,
                'verified_at' => Carbon::now(),
            ]);

            // Through TenantContext, so a failure in here does not leave the
            // half-built (and rolled back) tenant active for the caller.
            $this->context->run($tenant, function () use ($tenant, $owner): void {
                $roles = $this->createSystemRoles($tenant);

                TenantMembership::query()->create([
                    'user_id' => $owner->getKey(),
                    'status' => MembershipStatus::Active,
                    'is_owner' => true,
                    'joined_at' => Carbon::now(),
                ]);

                $owner->assignRole($roles[Role::OWNER]);
            });

            if ($status !== TenantStatus::Active) {
                $tenant->update(['status' => $status]);
            }

            return $tenant;
        });
    }

    /**
     * @return array<string, Role> keyed by role name
     */
    private function createSystemRoles(Tenant $tenant): array
    {
        $manager = app(RoleManager::class);
        $roles = [];

        foreach (DefaultRoles::definitions() as $name => $definition) {
            /** @var Role $role */
            $role = Role::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $name,
                'guard_name' => 'web',
                'description' => $definition['description'],
                'is_system' => true,
            ]);

            if ($definition['permissions'] !== []) {
                $manager->syncPermissions(null, $role, $definition['permissions'], $definition['scopes'] ?? []);
            }

            $roles[$name] = $role;
        }

        return $roles;
    }
}
