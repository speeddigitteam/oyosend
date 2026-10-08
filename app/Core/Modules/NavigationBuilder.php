<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Access\AccessChecker;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar for one person in the active workspace: only modules
 * the tenant is entitled to, only entries the person is permitted to open.
 */
final class NavigationBuilder
{
    /** Sidebar sections, in display order. */
    private const array SECTIONS = ['workspace', 'apps', 'manage'];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly AccessChecker $access,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<array{key: string, items: list<array<string, mixed>>}>
     */
    public function for(User $user): array
    {
        $tenant = $this->context->require();
        $entitled = $this->entitlements->entitledModules($tenant);
        $sections = array_fill_keys(self::SECTIONS, []);

        foreach ($this->modules->all() as $manifest) {
            if (! in_array($manifest->key, $entitled, true)) {
                continue;
            }

            foreach ($manifest->navigation as $item) {
                if ($item->permission !== null && ! $this->access->allows($user, $item->permission)) {
                    continue;
                }

                if (! Route::has($item->route)) {
                    continue;
                }

                $sections[$item->section][] = $item;
            }
        }

        $navigation = [];

        foreach ($sections as $key => $items) {
            if ($items === []) {
                continue;
            }

            usort($items, static fn (NavigationItem $a, NavigationItem $b): int => $a->order <=> $b->order);

            $navigation[] = [
                'key' => $key,
                'items' => array_map(static fn (NavigationItem $item): array => [
                    'key' => $item->key,
                    'route' => $item->route,
                    'href' => route($item->route),
                    'icon' => $item->icon,
                    'active' => $item->activePattern ?? $item->route,
                ], $items),
            ];
        }

        return $navigation;
    }
}
