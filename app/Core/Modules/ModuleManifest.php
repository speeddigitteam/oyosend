<?php

declare(strict_types=1);

namespace App\Core\Modules;

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
    }
}
