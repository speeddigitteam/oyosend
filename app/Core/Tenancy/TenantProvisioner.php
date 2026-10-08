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
 * system roles and its owner. Used by seeding today and by self-registration
 * and the Super Admin panel from Phase 2.
 */
final class TenantProvisioner
{
    public function __construct(private readonly TenantHost $hosts) {}

    /**
     * @param  array<string, mixed>  $attributes  Optional locale, timezone, currency, status, trial_ends_at.
     */
    public function provision(string $name, string $slug, User $owner, array $attributes = []): Tenant
    {
        $slug = Str::lower(trim($slug));

        if ($this->hosts->isReserved($slug) || preg_match('/^'.TenantHost::LABEL_PATTERN.'$/', $slug) !== 1) {
            throw new InvalidArgumentException("[{$slug}] cannot be used as a workspace address.");
        }

        return DB::transaction(function () use ($name, $slug, $owner, $attributes): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'status' => $attributes['status'] ?? TenantStatus::Active,
                'locale' => $attributes['locale'] ?? config('platform.defaults.locale'),
                'timezone' => $attributes['timezone'] ?? config('platform.defaults.timezone'),
                'currency' => $attributes['currency'] ?? config('platform.defaults.currency'),
                'trial_ends_at' => $attributes['trial_ends_at'] ?? null,
            ]);

            $tenant->domains()->create([
                'domain' => $slug,
                'type' => Domain::TYPE_SUBDOMAIN,
                'is_primary' => true,
                'verified_at' => Carbon::now(),
            ]);

            $tenant->run(function () use ($tenant, $owner): void {
                $roles = $this->createSystemRoles($tenant);

                TenantMembership::query()->create([
                    'user_id' => $owner->getKey(),
                    'status' => MembershipStatus::Active,
                    'is_owner' => true,
                    'joined_at' => Carbon::now(),
                ]);

                $owner->assignRole($roles[Role::OWNER]);
            });

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
                $manager->syncPermissions(null, $role, $definition['permissions']);
            }

            $roles[$name] = $role;
        }

        return $roles;
    }
}
