<?php

declare(strict_types=1);

namespace App\Core\Entitlements;

use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Models\AppEntitlement;
use App\Core\Billing\Models\Subscription;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;

/**
 * Entitlements from the workspace's plan.
 *
 *  - Core modules belong to every workspace that is accessible (not pending,
 *    not suspended) - also when its subscription has ended, so that its owner
 *    can still sign in and reach billing.
 *  - An optional module belongs to a workspace whose plan includes it, for as
 *    long as the subscription is in good standing (trial, active, or past due
 *    inside the grace period).
 *
 * A workspace without a subscription still has core modules and any active,
 * installed app licences it acquired separately through the App Store.
 */
final class PlanEntitlementChecker implements EntitlementChecker
{
    /** @var array<string, list<string>> tenant id => modules its plan includes; per-request memo */
    private array $included = [];

    /** @var array<string, list<string>> tenant id => final dependency-safe entitlements */
    private array $entitled = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function allows(Tenant $tenant, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->entitledModules($tenant), true);
    }

    public function includedByPlan(Tenant $tenant, string $moduleKey): bool
    {
        return $tenant->isAccessible()
            && in_array($moduleKey, $this->includedInPlan($tenant), true)
            && $this->allows($tenant, $moduleKey);
    }

    public function entitledModules(Tenant $tenant): array
    {
        if (! $tenant->isAccessible()) {
            return [];
        }

        if (isset($this->entitled[$tenant->id])) {
            return $this->entitled[$tenant->id];
        }

        $included = array_values(array_unique([
            ...$this->includedInPlan($tenant),
            ...$this->licensedApps($tenant),
        ]));

        $entitled = array_keys(array_filter(
            $this->modules->all(),
            static fn (ModuleManifest $manifest): bool => $manifest->core || in_array($manifest->key, $included, true),
        ));

        // A stale/manual licence never bypasses another module's dependency.
        do {
            $before = $entitled;
            $entitled = array_values(array_filter(
                $entitled,
                fn (string $key): bool => $this->modules->get($key)->core
                    || count(array_diff($this->modules->get($key)->dependencies, $entitled)) === 0,
            ));
        } while ($before !== $entitled);

        return $this->entitled[$tenant->id] = $entitled;
    }

    /**
     * Forget what was read about subscriptions. SubscriptionManager calls it
     * after every change, so the rest of the request sees the new plan.
     */
    public function forget(): void
    {
        $this->included = [];
        $this->entitled = [];
    }

    /**
     * @return list<string>
     */
    private function includedInPlan(Tenant $tenant): array
    {
        return $this->included[$tenant->id] ??= $this->read($tenant);
    }

    /**
     * @return list<string>
     */
    private function read(Tenant $tenant): array
    {
        /** @var Subscription|null $subscription */
        $subscription = $tenant->subscription()->with('plan')->first();

        if ($subscription === null || ! $subscription->grantsAccess()) {
            return [];
        }

        return array_values($subscription->plan->modules);
    }

    /** @return list<string> */
    private function licensedApps(Tenant $tenant): array
    {
        return AppEntitlement::forTenant($tenant)
            ->join('app_installations', 'app_installations.id', '=', 'app_entitlements.installation_id')
            ->join('apps', 'apps.id', '=', 'app_entitlements.app_id')
            ->where('app_installations.tenant_id', $tenant->getTenantKey())
            ->where('app_installations.status', InstallationStatus::Active)
            ->where('app_entitlements.status', EntitlementStatus::Active)
            ->where('app_entitlements.valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('app_entitlements.valid_until')
                ->orWhere('app_entitlements.valid_until', '>', now()))
            ->where('apps.is_published', true)
            ->pluck('apps.module_key')
            ->all();
    }
}
