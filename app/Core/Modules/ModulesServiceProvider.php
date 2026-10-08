<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Automation\AutomationRegistry;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Boots the module system: the registries, then every provider listed in
 * config/modules.php.
 */
final class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(AutomationRegistry::class);

        /** @var list<class-string<ModuleServiceProvider>> $providers */
        $providers = config('modules.providers', []);

        foreach ($providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $missing = $this->app->make(ModuleRegistry::class)->missingDependencies();

        if ($missing !== []) {
            $details = collect($missing)
                ->map(fn (array $dependencies, string $module): string => $module.' needs '.implode(', ', $dependencies))
                ->implode('; ');

            throw new RuntimeException("Module dependencies are not registered: {$details}.");
        }
    }
}
