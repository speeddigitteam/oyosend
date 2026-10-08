<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Crm;

use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;

/**
 * A stand-in optional app, built exactly the way a real module is: a
 * non-core manifest, its own tenant routes, a sidebar entry, a dashboard
 * widget and an automation trigger. Tests register it with
 * `registerModule()` to exercise everything that is gated by entitlement.
 */
final class CrmServiceProvider extends ModuleServiceProvider
{
    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'crm',
            name: 'CRM',
            version: '0.1.0',
            core: false,
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('crm.leads.view', 'leads', scopable: true),
                new PermissionDefinition('crm.leads.edit', 'leads', scopable: true),
            ],
            navigation: [
                new NavigationItem('leads', 'crm.leads.index', 'target', 'crm.leads.view', 'apps', 10, 'crm.leads.*'),
            ],
            widgets: [
                new WidgetDefinition('crm.pipeline', 'PipelineWidget', PipelineWidget::class, 'crm.leads.view', span: 6, order: 15),
            ],
            triggers: [
                LeadCreatedTrigger::class,
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
