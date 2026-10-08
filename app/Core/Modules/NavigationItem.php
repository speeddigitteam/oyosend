<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * A sidebar entry contributed by a module.
 *
 * An item is only ever sent to the browser when the tenant is entitled to the
 * module and the user holds `permission`. That is a convenience, not the
 * access control: the route behind it enforces both again.
 */
final readonly class NavigationItem
{
    /**
     * @param  string  $key  Unique, also the translation key suffix: `nav.{key}`.
     * @param  string  $route  Named route the entry links to.
     * @param  string  $icon  Icon name from the client icon set.
     * @param  string|null  $permission  Permission required to see the entry.
     * @param  string  $section  Sidebar section: `workspace`, `apps` or `manage`.
     * @param  string|null  $activePattern  Route-name pattern that marks the entry active.
     */
    public function __construct(
        public string $key,
        public string $route,
        public string $icon,
        public ?string $permission = null,
        public string $section = 'workspace',
        public int $order = 100,
        public ?string $activePattern = null,
    ) {}
}
