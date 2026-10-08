<?php

declare(strict_types=1);

namespace App\Core\Entitlements;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;

/**
 * Entitlements before the App Store exists.
 *
 * Core modules belong to every active tenant. Every other module is denied,
 * because nothing can have granted it yet: installs, purchases and licences
 * arrive with the App Store (Phase 3), which replaces this binding with one
 * backed by `app_entitlements`. Denying by default means an optional module
 * added before then is unreachable rather than accidentally free.
 */
final class CoreModulesEntitlementChecker implements EntitlementChecker
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function allows(Tenant $tenant, string $moduleKey): bool
    {
        if (! $tenant->isAccessible()) {
            return false;
        }

        return $this->modules->find($moduleKey)?->core === true;
    }

    public function entitledModules(Tenant $tenant): array
    {
        if (! $tenant->isAccessible()) {
            return [];
        }

        return array_keys(array_filter(
            $this->modules->all(),
            static fn (ModuleManifest $manifest): bool => $manifest->core,
        ));
    }
}
