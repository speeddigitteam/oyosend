<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Automation\AutomationRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base provider for a module. A module extends it, returns its manifest, and
 * loads its own routes and migrations from `bootModule()`.
 *
 * @see docs/MODULES.md
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    private ?ModuleManifest $manifest = null;

    /**
     * Describe the module. Called once per request lifecycle.
     */
    abstract protected function describe(): ModuleManifest;

    /**
     * Load routes, migrations, listeners and anything else the module boots.
     */
    protected function bootModule(): void {}

    final public function manifest(): ModuleManifest
    {
        return $this->manifest ??= $this->describe();
    }

    public function register(): void
    {
        $this->callAfterResolving(ModuleRegistry::class, function (ModuleRegistry $registry): void {
            $registry->register($this->manifest());
        });

        $this->callAfterResolving(AutomationRegistry::class, function (AutomationRegistry $registry): void {
            foreach ($this->manifest()->triggers as $trigger) {
                $registry->registerTrigger($trigger);
            }

            foreach ($this->manifest()->actions as $action) {
                $registry->registerAction($action);
            }
        });
    }

    public function boot(): void
    {
        if ($this->manifest()->morphMap !== []) {
            Relation::morphMap($this->manifest()->morphMap);
        }

        $this->bootModule();
    }

    /**
     * Load a routes file into the tenant workspace.
     *
     * Every route in it runs on `{tenant}.APP_DOMAIN` behind tenant
     * resolution and - unless the module is core - the module's entitlement.
     * Authentication and permissions are declared by the routes themselves.
     */
    protected function loadTenantRoutes(string $path): void
    {
        $manifest = $this->manifest();

        Route::domain('{tenant}.'.config('platform.domain'))
            ->middleware(['web', 'tenant', ...($manifest->core ? [] : ['entitled:'.$manifest->key])])
            ->group($path);
    }
}
