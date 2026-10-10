<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Crm;

use App\Core\AppStore\AppSettingDefinition;
use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\AppSettingType;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Modules\AppOffer;
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
            key: 'test_crm',
            name: 'Test CRM',
            version: '0.1.0',
            core: false,
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('test_crm.leads.view', 'leads', scopable: true),
                new PermissionDefinition('test_crm.leads.edit', 'leads', scopable: true),
            ],
            navigation: [
                new NavigationItem('test_leads', 'test_crm.leads.index', 'target', 'test_crm.leads.view', 'apps', 10, 'test_crm.leads.*'),
            ],
            widgets: [
                new WidgetDefinition('test_crm.pipeline', 'PipelineWidget', PipelineWidget::class, 'test_crm.leads.view', span: 6, order: 15),
            ],
            triggers: [
                LeadCreatedTrigger::class,
            ],
            description: 'Manage leads, opportunities and a shared sales pipeline.',
            category: AppCategory::CrmSales,
            icon: 'target',
            featured: true,
            popular: true,
            features: ['Lead management', 'Sales pipeline', 'Team visibility'],
            offers: [
                new AppOffer('community', 'Community', AppBillingType::Free),
                new AppOffer('monthly', 'Monthly', AppBillingType::Monthly, 'BDT', 250000),
                new AppOffer('trial', '14-day trial', AppBillingType::Monthly, 'BDT', 250000, trialDays: 14),
                new AppOffer('lifetime', 'Lifetime', AppBillingType::OneTime, 'BDT', 2500000),
            ],
            releaseNotes: 'Initial App Store release.',
            routes: ['test_crm.leads.index', 'test_crm.ping'],
            scheduledJobs: [
                new ScheduledJobDefinition('test_crm.pipeline_refresh', CrmScheduledJob::class, ScheduledJobFrequency::EveryMinute),
            ],
            settings: [
                new AppSettingDefinition(
                    'pipeline_view',
                    'Default pipeline view',
                    AppSettingType::Select,
                    required: true,
                    default: 'board',
                    options: ['board' => 'Board', 'list' => 'List'],
                ),
                new AppSettingDefinition('daily_digest', 'Daily pipeline digest', AppSettingType::Boolean, default: true),
                new AppSettingDefinition('webhook_secret', 'Webhook signing secret', AppSettingType::Secret, maxLength: 500),
            ],
            lifecycle: CrmLifecycle::class,
        );
    }

    protected function bootModule(): void
    {
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
