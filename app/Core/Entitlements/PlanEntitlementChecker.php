<?php

declare(strict_types=1);

namespace App\Core\Entitlements;

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
 * A workspace without a subscription has the core modules only. Per-app
 * installs and licences join this with the App Store (Phase 3).
 */
final class PlanEntitlementChecker implements EntitlementChecker
{
    /** @var array<string, list<string>> tenant id => modules its plan includes; per-request memo */
    private array $included = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function allows(Tenant $tenant, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->entitledModules($tenant), true);
    }

    public function entitledModules(Tenant $tenant): array
    {
        if (! $tenant->isAccessible()) {
            return [];
        }

        $included = $this->includedInPlan($tenant);

        return array_keys(array_filter(
            $this->modules->all(),
            static fn (ModuleManifest $manifest): bool => $manifest->core || in_array($manifest->key, $included, true),
        ));
    }

    /**
     * Forget what was read about subscriptions. SubscriptionManager calls it
     * after every change, so the rest of the request sees the new plan.
     */
    public function forget(): void
    {
        $this->included = [];
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
}
