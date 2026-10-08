<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Core\Access\AccessChecker;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WidgetDefinition;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;

/**
 * Assembles the dashboard for one person: widgets of entitled modules that
 * they are permitted to see. A widget's data provider is not even invoked
 * for someone who may not see the widget.
 */
final class DashboardBuilder
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly AccessChecker $access,
        private readonly TenantContext $context,
        private readonly Container $container,
    ) {}

    /**
     * @return list<array{key: string, component: string, span: int, data: array<string, mixed>}>
     */
    public function for(User $user): array
    {
        $entitled = $this->entitlements->entitledModules($this->context->require());
        $widgets = [];

        foreach ($this->modules->all() as $manifest) {
            if (! in_array($manifest->key, $entitled, true)) {
                continue;
            }

            foreach ($manifest->widgets as $widget) {
                if ($widget->permission === null || $this->access->allows($user, $widget->permission)) {
                    $widgets[] = $widget;
                }
            }
        }

        usort($widgets, static fn (WidgetDefinition $a, WidgetDefinition $b): int => $a->order <=> $b->order);

        return array_map(fn (WidgetDefinition $widget): array => [
            'key' => $widget->key,
            'component' => $widget->component,
            'span' => $widget->span,
            'data' => $this->container->call($this->container->make($widget->provider), ['user' => $user]),
        ], $widgets);
    }
}
