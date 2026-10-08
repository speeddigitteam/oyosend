<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Tenancy\Models\Tenant;
use Tests\Support\Fixtures\GrantsModules;
use Tests\TestCase;

/**
 * Lets a test add a module to the build and decide which tenants may use it.
 *
 * @mixin TestCase
 */
trait InteractsWithModules
{
    /**
     * Register a module provider for this test, as if it were listed in
     * config/modules.php: its manifest joins the registry, its routes are
     * loaded and its permissions are synced into the catalogue.
     *
     * @param  class-string<ModuleServiceProvider>  $provider
     */
    protected function registerModule(string $provider): ModuleManifest
    {
        /** @var ModuleServiceProvider $instance */
        $instance = $this->app->register($provider);

        // Routes added after boot are not in the name and action lookups yet.
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        $this->syncPermissions();

        return $this->app->make(ModuleRegistry::class)->get($instance->manifest()->key);
    }

    /**
     * Entitle a tenant to optional modules, on top of the core ones every
     * tenant has. Without this a non-core module is denied to everybody.
     */
    protected function entitle(Tenant $tenant, string ...$modules): void
    {
        $checker = $this->app->make(EntitlementChecker::class);

        if (! $checker instanceof GrantsModules) {
            $checker = $this->app->make(GrantsModules::class);

            $this->app->instance(EntitlementChecker::class, $checker);
            $this->app->forgetScopedInstances();
        }

        $checker->grant($tenant, ...$modules);
    }
}
