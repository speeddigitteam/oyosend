<?php

declare(strict_types=1);

namespace App\Core\Modules;

use InvalidArgumentException;

/**
 * The set of modules compiled into this build of the platform.
 *
 * Being registered makes a module's code available. Whether a tenant may use
 * it is decided separately by the entitlement checker.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    /** @var array<string, PermissionDefinition>|null */
    private ?array $permissionIndex = null;

    public function register(ModuleManifest $manifest): void
    {
        if (isset($this->modules[$manifest->key])) {
            throw new InvalidArgumentException("Module [{$manifest->key}] is already registered.");
        }

        $this->modules[$manifest->key] = $manifest;
        $this->permissionIndex = null;
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function all(): array
    {
        return $this->modules;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    public function get(string $key): ModuleManifest
    {
        return $this->modules[$key] ?? throw new InvalidArgumentException("Unknown module [{$key}].");
    }

    public function find(string $key): ?ModuleManifest
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * @return array<string, PermissionDefinition> keyed by permission name
     */
    public function permissions(): array
    {
        if ($this->permissionIndex !== null) {
            return $this->permissionIndex;
        }

        $index = [];

        foreach ($this->modules as $manifest) {
            foreach ($manifest->permissions as $permission) {
                if (isset($index[$permission->name])) {
                    throw new InvalidArgumentException("Permission [{$permission->name}] is registered twice.");
                }

                $index[$permission->name] = $permission;
            }
        }

        return $this->permissionIndex = $index;
    }

    public function findPermission(string $name): ?PermissionDefinition
    {
        return $this->permissions()[$name] ?? null;
    }

    /**
     * Modules whose dependencies are not registered - a build-time mistake.
     *
     * @return array<string, list<string>> module key => missing dependency keys
     */
    public function missingDependencies(): array
    {
        $missing = [];

        foreach ($this->modules as $manifest) {
            $absent = array_values(array_filter(
                $manifest->dependencies,
                fn (string $dependency): bool => ! $this->has($dependency),
            ));

            if ($absent !== []) {
                $missing[$manifest->key] = $absent;
            }
        }

        return $missing;
    }
}
