<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;

/**
 * The product's entitlement rules plus explicit grants that a test can also take away again, to prove the
 * engine re-checks entitlement at every step and not only when a workflow is published.
 */
final class RevocableEntitlements implements EntitlementChecker
{
    /** @var array<string, list<string>> */
    private array $grants = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function grant(Tenant $tenant, string ...$modules): void
    {
        $this->grants[$tenant->id] = array_values(array_unique([...($this->grants[$tenant->id] ?? []), ...$modules]));
    }

    public function revoke(Tenant $tenant, string ...$modules): void
    {
        $this->grants[$tenant->id] = array_values(array_diff($this->grants[$tenant->id] ?? [], $modules));
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

        $granted = array_filter($this->grants[$tenant->id] ?? [], fn (string $module): bool => $this->modules->has($module));

        return array_values(array_unique([...app(PlanEntitlementChecker::class)->entitledModules($tenant), ...$granted]));
    }
}
