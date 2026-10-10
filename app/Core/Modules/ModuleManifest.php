<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\AppStore\AppSettingDefinition;
use App\Core\AppStore\Contracts\ModuleLifecycle;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Automation\Contracts\TriggerDefinition;
use InvalidArgumentException;

/**
 * Everything the platform needs to know about a module, declared in one place.
 *
 * The App Store (Phase 3) reads the same manifest to install, license and
 * gate a module per tenant; nothing about a module is discovered by scanning.
 */
final readonly class ModuleManifest
{
    /**
     * @param  string  $key  Stable identifier and permission prefix: `crm`.
     * @param  string  $name  Human name, shown in the App Store.
     * @param  string  $version  Semantic version of the module.
     * @param  bool  $core  Core modules are part of every tenant and need no entitlement.
     * @param  list<string>  $dependencies  Keys of modules that must be active first.
     * @param  list<PermissionDefinition>  $permissions
     * @param  list<NavigationItem>  $navigation
     * @param  list<WidgetDefinition>  $widgets
     * @param  list<class-string<TriggerDefinition>>  $triggers  Automation triggers.
     * @param  list<class-string<ActionDefinition>>  $actions  Automation actions.
     * @param  array<string, class-string>  $morphMap  Morph aliases for the module's models.
     * @param  list<LimitDefinition>  $limits  What a plan can cap for this module.
     * @param  list<string>  $features  Short, concrete catalogue feature labels.
     * @param  list<string>  $screenshots  Public asset paths; never remote tenant content.
     * @param  list<AppOffer>  $offers  Ways the app can be licensed.
     * @param  list<string>  $routes  Named routes the module owns.
     * @param  list<ScheduledJobDefinition>  $scheduledJobs  Tenant jobs; execution re-checks entitlement.
     * @param  list<AppSettingDefinition>  $settings  Typed per-workspace activation settings.
     * @param  class-string<ModuleLifecycle>|null  $lifecycle
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $version,
        public bool $core = false,
        public array $dependencies = [],
        public array $permissions = [],
        public array $navigation = [],
        public array $widgets = [],
        public array $triggers = [],
        public array $actions = [],
        public array $morphMap = [],
        public array $limits = [],
        public ?string $description = null,
        public ?AppCategory $category = null,
        public string $icon = 'package',
        public bool $featured = false,
        public bool $popular = false,
        public array $features = [],
        public array $screenshots = [],
        public array $offers = [],
        public string $compatibility = 'Oyosend 1.x',
        public ?string $releaseNotes = null,
        public array $routes = [],
        public array $scheduledJobs = [],
        public array $settings = [],
        public ?string $lifecycle = null,
    ) {
        // `D`: without it `$` would also accept a key that ends in a line break.
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid module key [{$key}].");
        }

        foreach ($permissions as $permission) {
            if ($permission->module() !== $key) {
                throw new InvalidArgumentException(
                    "Permission [{$permission->name}] must be prefixed with its module key [{$key}.].",
                );
            }
        }

        foreach ($limits as $limit) {
            if ($limit->module() !== $key) {
                throw new InvalidArgumentException("Limit [{$limit->key}] must be prefixed with its module key [{$key}.].");
            }
        }

        foreach ($routes as $route) {
            if (! str_starts_with($route, $key.'.')) {
                throw new InvalidArgumentException("Route [{$route}] must be prefixed with its module key [{$key}.].");
            }
        }

        $scheduledJobKeys = [];

        foreach ($scheduledJobs as $scheduledJob) {
            $scheduledJob = self::scheduledJob($scheduledJob);

            if (! str_starts_with($scheduledJob->key, $key.'.')) {
                throw new InvalidArgumentException(
                    "Scheduled job [{$scheduledJob->key}] must be prefixed with its module key [{$key}.].",
                );
            }

            if (isset($scheduledJobKeys[$scheduledJob->key])) {
                throw new InvalidArgumentException("Scheduled job [{$scheduledJob->key}] is registered twice in [{$key}].");
            }

            $scheduledJobKeys[$scheduledJob->key] = true;
        }

        $settingKeys = [];

        foreach ($settings as $setting) {
            $setting = self::appSetting($setting);

            if (isset($settingKeys[$setting->key])) {
                throw new InvalidArgumentException("App setting [{$setting->key}] is registered twice in [{$key}].");
            }

            $settingKeys[$setting->key] = true;
        }

        $offerKeys = [];

        foreach ($offers as $offer) {
            if (isset($offerKeys[$offer->key])) {
                throw new InvalidArgumentException("App offer [{$offer->key}] is registered twice in [{$key}].");
            }

            $offerKeys[$offer->key] = true;
        }

        if ($lifecycle !== null && ! is_subclass_of($lifecycle, ModuleLifecycle::class)) {
            throw new InvalidArgumentException("Lifecycle [{$lifecycle}] must implement ModuleLifecycle.");
        }
    }

    private static function scheduledJob(mixed $definition): ScheduledJobDefinition
    {
        if (! $definition instanceof ScheduledJobDefinition) {
            throw new InvalidArgumentException('Scheduled jobs must be ScheduledJobDefinition instances.');
        }

        return $definition;
    }

    private static function appSetting(mixed $definition): AppSettingDefinition
    {
        if (! $definition instanceof AppSettingDefinition) {
            throw new InvalidArgumentException('App settings must be AppSettingDefinition instances.');
        }

        return $definition;
    }
}
