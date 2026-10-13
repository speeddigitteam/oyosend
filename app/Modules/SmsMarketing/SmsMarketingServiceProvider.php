<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\SmsMarketing\Actions\GroupManager;
use App\Modules\SmsMarketing\Actions\RecipientStatusApplier;
use App\Modules\SmsMarketing\Actions\SmsMarketingContactMergeReference;
use App\Modules\SmsMarketing\Automation\Actions\AddContactToGroupAction;
use App\Modules\SmsMarketing\Automation\Triggers\CampaignCompletedTrigger;
use App\Modules\SmsMarketing\Automation\Triggers\CampaignLaunchedTrigger;
use App\Modules\SmsMarketing\Jobs\ReconcileDeliveries;
use App\Modules\SmsMarketing\Jobs\RunCampaigns;
use App\Modules\SmsMarketing\Listeners\ApplyMarketingStatus;
use App\Modules\SmsMarketing\Models\AutomationActionReceipt;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\CampaignGroup;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Models\Import;
use App\Modules\SmsMarketing\Models\Recipient;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Setting;
use App\Modules\SmsMarketing\Models\Template;
use App\Modules\SmsMarketing\Reporting\ReportProviders;
use App\Modules\SmsMarketing\Widgets\SmsMarketingOverviewWidget;
use Illuminate\Support\Facades\Event;

/**
 * SMS Marketing (category SMS marketing): bulk and scheduled SMS campaigns to contact groups, message
 * templates with a fixed variable allow-list, recorded sender ids, delivery status and usage.
 *
 * It never talks to an SMS provider. Everything goes through the core contracts of the communication
 * platform (`MarketingAudience`, `UnsubscribeLinks`, `ChannelGateway`); without a configured SMS provider the
 * channel is `not_configured` and campaigns cannot be launched. See docs/SMS_MARKETING.md.
 */
final class SmsMarketingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
        $this->app->tag(SmsMarketingContactMergeReference::class, ContactMergeReference::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'sms_marketing',
            name: 'SMS Marketing',
            version: '1.0.0',
            dependencies: ['core', 'communication'],
            permissions: [
                new PermissionDefinition('sms_marketing.campaigns.view', 'sms_marketing_campaigns', scopable: true),
                new PermissionDefinition('sms_marketing.campaigns.create', 'sms_marketing_campaigns'),
                new PermissionDefinition('sms_marketing.campaigns.edit', 'sms_marketing_campaigns', scopable: true),
                new PermissionDefinition('sms_marketing.campaigns.delete', 'sms_marketing_campaigns', scopable: true),
                new PermissionDefinition('sms_marketing.campaigns.launch', 'sms_marketing_campaigns', scopable: true, sensitive: true),
                new PermissionDefinition('sms_marketing.groups.view', 'sms_marketing_groups'),
                new PermissionDefinition('sms_marketing.groups.manage', 'sms_marketing_groups'),
                new PermissionDefinition('sms_marketing.templates.view', 'sms_marketing_templates'),
                new PermissionDefinition('sms_marketing.templates.manage', 'sms_marketing_templates'),
                new PermissionDefinition('sms_marketing.senders.view', 'sms_marketing_senders'),
                new PermissionDefinition('sms_marketing.senders.manage', 'sms_marketing_senders', sensitive: true),
                new PermissionDefinition('sms_marketing.analytics.view', 'sms_marketing_analytics'),
                new PermissionDefinition('sms_marketing.settings.manage', 'sms_marketing_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('smsCampaigns', 'sms_marketing.campaigns.index', 'smartphone', 'sms_marketing.campaigns.view', 'apps', 120, 'sms_marketing.campaigns.*'),
                new NavigationItem('smsGroups', 'sms_marketing.groups.index', 'users-round', 'sms_marketing.groups.view', 'apps', 121, 'sms_marketing.groups.*'),
                new NavigationItem('smsTemplates', 'sms_marketing.templates.index', 'message-square-text', 'sms_marketing.templates.view', 'apps', 122, 'sms_marketing.templates.*'),
                new NavigationItem('smsSenders', 'sms_marketing.senders.index', 'badge-check', 'sms_marketing.senders.view', 'apps', 123, 'sms_marketing.senders.*'),
                new NavigationItem('smsAnalytics', 'sms_marketing.analytics.index', 'chart-no-axes-combined', 'sms_marketing.analytics.view', 'apps', 124, 'sms_marketing.analytics.*'),
                new NavigationItem('smsSettings', 'sms_marketing.settings.index', 'settings', 'sms_marketing.settings.manage', 'apps', 900, 'sms_marketing.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('sms_marketing.overview', 'SmsMarketingOverviewWidget', SmsMarketingOverviewWidget::class, 'sms_marketing.campaigns.view', span: 4, order: 20),
            ],
            triggers: [CampaignLaunchedTrigger::class, CampaignCompletedTrigger::class],
            actions: [AddContactToGroupAction::class],
            morphMap: [
                'sms_marketing_setting' => Setting::class,
                'sms_marketing_sender_id' => SenderId::class,
                'sms_marketing_group' => Group::class,
                'sms_marketing_group_member' => GroupMember::class,
                'sms_marketing_template' => Template::class,
                'sms_marketing_campaign' => Campaign::class,
                'sms_marketing_campaign_group' => CampaignGroup::class,
                'sms_marketing_recipient' => Recipient::class,
                'sms_marketing_import' => Import::class,
                'sms_marketing_automation_receipt' => AutomationActionReceipt::class,
            ],
            limits: [
                new LimitDefinition(RecipientStatusApplier::LIMIT),
                new LimitDefinition(
                    GroupManager::LIMIT_CONTACTS,
                    LimitKind::Gauge,
                    static fn (): int => GroupManager::distinctContacts(),
                ),
            ],
            description: 'Send bulk and scheduled SMS campaigns to contact groups with consent checks, opt-out links, segment-aware cost estimates and delivery status.',
            category: AppCategory::SmsMarketing,
            icon: 'smartphone',
            features: [
                'Bulk and scheduled campaigns to contact groups',
                'Consent and suppression checked at send time; opt-out link in every message',
                'Unicode and Bengali aware segment counting with cost estimates',
                'Message templates with personalization from a fixed list',
                'Recorded sender ids and delivery status from the provider',
                'Throttling, a daily cap and bounded retries of failed messages',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Initial SMS Marketing release. Sending needs an SMS provider connected in Communication settings; without one campaigns can be drafted and estimated but not launched.',
            routes: [
                'sms_marketing.campaigns.index',
                'sms_marketing.campaigns.create',
                'sms_marketing.campaigns.store',
                'sms_marketing.campaigns.estimate',
                'sms_marketing.campaigns.show',
                'sms_marketing.campaigns.edit',
                'sms_marketing.campaigns.update',
                'sms_marketing.campaigns.destroy',
                'sms_marketing.campaigns.launch',
                'sms_marketing.campaigns.cancel',
                'sms_marketing.campaigns.retry',
                'sms_marketing.campaigns.export',
                'sms_marketing.groups.index',
                'sms_marketing.groups.template',
                'sms_marketing.groups.store',
                'sms_marketing.groups.show',
                'sms_marketing.groups.update',
                'sms_marketing.groups.destroy',
                'sms_marketing.groups.members.store',
                'sms_marketing.groups.members.destroy',
                'sms_marketing.groups.import',
                'sms_marketing.groups.export',
                'sms_marketing.templates.index',
                'sms_marketing.templates.preview',
                'sms_marketing.templates.store',
                'sms_marketing.templates.update',
                'sms_marketing.templates.destroy',
                'sms_marketing.senders.index',
                'sms_marketing.senders.store',
                'sms_marketing.senders.update',
                'sms_marketing.senders.destroy',
                'sms_marketing.analytics.index',
                'sms_marketing.settings.index',
                'sms_marketing.settings.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('sms_marketing.run_campaigns', RunCampaigns::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('sms_marketing.reconcile', ReconcileDeliveries::class, ScheduledJobFrequency::Hourly),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        // Delivery status arrives through the core event, never through the communication module's own events.
        Event::listen(MarketingMessageStatusChanged::class, [ApplyMarketingStatus::class, 'handle']);
    }
}
