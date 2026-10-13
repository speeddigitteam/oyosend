<?php

declare(strict_types=1);

namespace App\Modules\CRM;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\CRM\Actions\CrmAudienceService;
use App\Modules\CRM\Actions\CrmContactMergeReference;
use App\Modules\CRM\Actions\LeadIntakeService;
use App\Modules\CRM\Automation\Actions\AssignLeadAction;
use App\Modules\CRM\Automation\Actions\CreateFollowUpAction;
use App\Modules\CRM\Automation\Actions\CreateLeadAction;
use App\Modules\CRM\Automation\Actions\MoveDealStageAction;
use App\Modules\CRM\Automation\Triggers\ActivityCompletedTrigger;
use App\Modules\CRM\Automation\Triggers\DealWonTrigger;
use App\Modules\CRM\Automation\Triggers\LeadCreatedTrigger;
use App\Modules\CRM\Automation\Triggers\LeadQualifiedTrigger;
use App\Modules\CRM\Automation\Triggers\QuoteAcceptedTrigger;
use App\Modules\CRM\Automation\Triggers\SalesOrderStatusChangedTrigger;
use App\Modules\CRM\CustomerRecords\ActivitiesSection;
use App\Modules\CRM\CustomerRecords\DealsSection;
use App\Modules\CRM\CustomerRecords\LeadsSection;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\CustomField;
use App\Modules\CRM\Models\CustomFieldValue;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealStageHistory;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Note;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use App\Modules\CRM\Models\PriceList;
use App\Modules\CRM\Models\PriceListItem;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\QuoteLine;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderLine;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\Tag;
use App\Modules\CRM\Models\TimelineEntry;
use App\Modules\CRM\Reporting\ReportProviders;
use App\Modules\CRM\Widgets\PipelineWidget;

/** First-party CRM and sales workspace module. */
final class CRMServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        // Replaces the core null objects; each answers `unavailable` / empty itself for a workspace without CRM.
        $this->app->bind(LeadIntake::class, LeadIntakeService::class);
        $this->app->bind(CrmAudience::class, CrmAudienceService::class);

        $this->app->tag(CrmContactMergeReference::class, ContactMergeReference::class);
        $this->app->tag([LeadsSection::class, DealsSection::class, ActivitiesSection::class], CustomerRecordProvider::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'crm',
            name: 'CRM & Sales',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('crm.leads.view', 'crm_leads', scopable: true),
                new PermissionDefinition('crm.leads.edit', 'crm_leads', scopable: true),
                new PermissionDefinition('crm.leads.qualify', 'crm_leads', scopable: true),
                new PermissionDefinition('crm.leads.assign', 'crm_leads', scopable: true),
                new PermissionDefinition('crm.deals.view', 'crm_deals', scopable: true),
                new PermissionDefinition('crm.deals.edit', 'crm_deals', scopable: true),
                new PermissionDefinition('crm.deals.assign', 'crm_deals', scopable: true),
                new PermissionDefinition('crm.activities.view', 'crm_activities', scopable: true),
                new PermissionDefinition('crm.activities.edit', 'crm_activities', scopable: true),
                new PermissionDefinition('crm.customers.view', 'crm_customers', scopable: true),
                new PermissionDefinition('crm.customers.manage', 'crm_customers', scopable: true),
                new PermissionDefinition('crm.products.view', 'crm_products'),
                new PermissionDefinition('crm.products.manage', 'crm_products'),
                new PermissionDefinition('crm.quotes.view', 'crm_quotes', scopable: true),
                new PermissionDefinition('crm.quotes.manage', 'crm_quotes', scopable: true),
                new PermissionDefinition('crm.quotes.accept', 'crm_quotes', scopable: true),
                new PermissionDefinition('crm.orders.view', 'crm_orders', scopable: true),
                new PermissionDefinition('crm.orders.manage', 'crm_orders', scopable: true),
                new PermissionDefinition('crm.contracts.view', 'crm_contracts', scopable: true),
                new PermissionDefinition('crm.contracts.manage', 'crm_contracts', scopable: true),
                new PermissionDefinition('crm.targets.view', 'crm_targets'),
                new PermissionDefinition('crm.targets.manage', 'crm_targets'),
                new PermissionDefinition('crm.reports.view', 'crm_reports'),
                new PermissionDefinition('crm.settings.manage', 'crm_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('crmLeads', 'crm.leads.index', 'contact-round', 'crm.leads.view', 'apps', 10, 'crm.leads.*'),
                new NavigationItem('crmDeals', 'crm.deals.index', 'handshake', 'crm.deals.view', 'apps', 20, 'crm.deals.*'),
                new NavigationItem('crmActivities', 'crm.activities.index', 'list-checks', 'crm.activities.view', 'apps', 30, 'crm.activities.*'),
                new NavigationItem('crmCustomers', 'crm.sales.customers.index', 'contact-round', 'crm.customers.view', 'apps', 40, 'crm.sales.customers.*'),
                new NavigationItem('crmProducts', 'crm.sales.products.index', 'package', 'crm.products.view', 'apps', 50, 'crm.sales.products.*'),
                new NavigationItem('crmQuotes', 'crm.sales.quotes.index', 'receipt', 'crm.quotes.view', 'apps', 60, 'crm.sales.quotes.*'),
                new NavigationItem('crmOrders', 'crm.sales.orders.index', 'list-checks', 'crm.orders.view', 'apps', 70, 'crm.sales.orders.*'),
                new NavigationItem('crmContracts', 'crm.sales.contracts.index', 'scroll-text', 'crm.contracts.view', 'apps', 80, 'crm.sales.contracts.*'),
                new NavigationItem('crmTargets', 'crm.sales.targets.index', 'target', 'crm.targets.view', 'apps', 90, 'crm.sales.targets.*'),
                new NavigationItem('crmReports', 'crm.sales.reports.index', 'chart-no-axes-combined', 'crm.reports.view', 'apps', 100, 'crm.sales.reports.*'),
                new NavigationItem('crmSettings', 'crm.settings.index', 'settings', 'crm.settings.manage', 'apps', 900, 'crm.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('crm.pipeline', 'PipelineWidget', PipelineWidget::class, 'crm.deals.view', span: 8, order: 15),
            ],
            triggers: [
                LeadCreatedTrigger::class,
                LeadQualifiedTrigger::class,
                DealWonTrigger::class,
                ActivityCompletedTrigger::class,
                QuoteAcceptedTrigger::class,
                SalesOrderStatusChangedTrigger::class,
            ],
            actions: [
                AssignLeadAction::class,
                CreateLeadAction::class,
                CreateFollowUpAction::class,
                MoveDealStageAction::class,
            ],
            morphMap: [
                'crm_lead_source' => LeadSource::class,
                'crm_pipeline' => Pipeline::class,
                'crm_pipeline_stage' => PipelineStage::class,
                'crm_lead' => Lead::class,
                'crm_deal' => Deal::class,
                'crm_deal_stage_history' => DealStageHistory::class,
                'crm_activity' => Activity::class,
                'crm_note' => Note::class,
                'crm_tag' => Tag::class,
                'crm_custom_field' => CustomField::class,
                'crm_custom_field_value' => CustomFieldValue::class,
                'crm_segment' => Segment::class,
                'crm_attachment' => Attachment::class,
                'crm_timeline_entry' => TimelineEntry::class,
                'crm_product' => Product::class,
                'crm_price_list' => PriceList::class,
                'crm_price_list_item' => PriceListItem::class,
                'crm_quote' => Quote::class,
                'crm_quote_line' => QuoteLine::class,
                'crm_sales_order' => SalesOrder::class,
                'crm_sales_order_line' => SalesOrderLine::class,
                'crm_contract' => Contract::class,
                'crm_sales_target' => SalesTarget::class,
                'crm_customer_profile' => CustomerProfile::class,
            ],
            description: 'Turn shared contacts into qualified leads, managed opportunities, quotations and sales orders.',
            category: AppCategory::CrmSales,
            icon: 'handshake',
            featured: true,
            popular: true,
            features: [
                'Lead qualification and assignment',
                'Visual deal pipelines and activities',
                'Products, quotations and sales orders',
                'Customer lifecycle and sales forecasting',
            ],
            offers: [
                new AppOffer('included', 'Included', AppBillingType::Free),
            ],
            releaseNotes: 'Initial CRM and sales release.',
            routes: [
                'crm.activities.index',
                'crm.activities.store',
                'crm.activities.update',
                'crm.activities.complete',
                'crm.activities.cancel',
                'crm.deals.index',
                'crm.deals.store',
                'crm.deals.show',
                'crm.deals.update',
                'crm.deals.assign',
                'crm.deals.attachments.store',
                'crm.deals.attachments.download',
                'crm.deals.attachments.destroy',
                'crm.deals.custom-values.update',
                'crm.deals.notes.store',
                'crm.deals.stage',
                'crm.deals.tags.update',
                'crm.leads.index',
                'crm.leads.store',
                'crm.leads.show',
                'crm.leads.update',
                'crm.leads.assign',
                'crm.leads.attachments.store',
                'crm.leads.attachments.download',
                'crm.leads.attachments.destroy',
                'crm.leads.custom-values.update',
                'crm.leads.notes.store',
                'crm.leads.qualify',
                'crm.leads.tags.update',
                'crm.sales.contracts.index',
                'crm.sales.contracts.store',
                'crm.sales.contracts.update',
                'crm.sales.contracts.status',
                'crm.sales.customers.index',
                'crm.sales.customers.show',
                'crm.sales.customers.update',
                'crm.sales.customers.notes.store',
                'crm.sales.customers.tags.update',
                'crm.sales.customers.custom-values.update',
                'crm.sales.customers.attachments.store',
                'crm.sales.customers.attachments.download',
                'crm.sales.customers.attachments.destroy',
                'crm.sales.orders.index',
                'crm.sales.orders.show',
                'crm.sales.orders.status',
                'crm.sales.price-lists.index',
                'crm.sales.price-lists.store',
                'crm.sales.price-lists.update',
                'crm.sales.price-lists.items.store',
                'crm.sales.price-lists.items.destroy',
                'crm.sales.products.index',
                'crm.sales.products.store',
                'crm.sales.products.update',
                'crm.sales.quotes.index',
                'crm.sales.quotes.store',
                'crm.sales.quotes.show',
                'crm.sales.quotes.update',
                'crm.sales.quotes.accept',
                'crm.sales.quotes.cancel',
                'crm.sales.quotes.reject',
                'crm.sales.quotes.send',
                'crm.sales.reports.index',
                'crm.sales.targets.index',
                'crm.sales.targets.store',
                'crm.sales.targets.update',
                'crm.settings.index',
                'crm.settings.custom-fields.store',
                'crm.settings.pipelines.store',
                'crm.settings.pipelines.update',
                'crm.settings.stages.store',
                'crm.settings.segments.store',
                'crm.settings.sources.store',
                'crm.settings.tags.store',
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
