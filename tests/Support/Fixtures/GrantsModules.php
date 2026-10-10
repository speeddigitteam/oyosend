<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;

/**
 * The product's entitlement rules plus explicit grants made by a test - for
 * tests about what an entitlement does, which should not have to set up a
 * plan and a subscription to get one.
 */
final class GrantsModules implements EntitlementChecker
{
    /** @var array<string, list<string>> tenant id => module keys */
    private array $grants = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function grant(Tenant $tenant, string ...$modules): void
    {
        $this->grants[$tenant->id] = array_values(array_unique([...($this->grants[$tenant->id] ?? []), ...$modules]));
    }

    public function allows(Tenant $tenant, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->entitledModules($tenant), true);
    }

    public function entitledModules(Tenant $tenant): array
    {
        if (! $tenant->isAccessible()) {
            return [];
        }

        $granted = array_filter(
            $this->grants[$tenant->id] ?? [],
            fn (string $module): bool => $this->modules->has($module),
        );

        // Asked for on every call: this fixture outlives a simulated request,
        // the product's checker (scoped, with a per-request memo) must not.
        $fromPlan = app(PlanEntitlementChecker::class)->entitledModules($tenant);

        return array_values(array_unique([...$fromPlan, ...$granted]));
    }
}
