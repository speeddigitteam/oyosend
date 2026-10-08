<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\CoreModulesEntitlementChecker;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;

/**
 * The Phase 1 entitlement rules plus explicit grants made by a test - a
 * stand-in for the App Store's entitlement store (Phase 3).
 */
final class GrantsModules implements EntitlementChecker
{
    /** @var array<string, list<string>> tenant id => module keys */
    private array $grants = [];

    public function __construct(
        private readonly CoreModulesEntitlementChecker $core,
        private readonly ModuleRegistry $modules,
    ) {}

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

        return array_values(array_unique([...$this->core->entitledModules($tenant), ...$granted]));
    }
}
