<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Contacts\Events\ContactDeleted;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\EmailMarketingContactMergeReference;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Automation\Actions\AddToListAction;
use App\Modules\EmailMarketing\Automation\Triggers\CampaignSentTrigger;
use App\Modules\EmailMarketing\Automation\Triggers\SubscriberSubscribedTrigger;
use App\Modules\EmailMarketing\Jobs\RunEmailMarketing;
use App\Modules\EmailMarketing\Listeners\ForgetDeletedContact;
use App\Modules\EmailMarketing\Listeners\MirrorMessageStatus;
use App\Modules\EmailMarketing\Listeners\MirrorUnsubscribe;
use App\Modules\EmailMarketing\Models\AutomationActionReceipt;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\ImportRun;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Models\Setting;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Models\Template;
use App\Modules\EmailMarketing\Reporting\ReportProviders;
use App\Modules\EmailMarketing\Widgets\EmailMarketingOverviewWidget;
use Illuminate\Support\Facades\Event;

/**
 * Email marketing: lists of shared contacts, a structured block editor, campaigns with A/B subjects and click
 * measurement, welcome and drip sequences, CSV import and export, and honest delivery reports. Everything is sent
 * through the platform's communication contracts; nothing here talks to a mailer. See docs/EMAIL_MARKETING.md.
 */
final class EmailMarketingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
        $this->app->tag(EmailMarketingContactMergeReference::class, ContactMergeReference::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'email_marketing',
            name: 'Email Marketing',
            version: '1.1.0',
            dependencies: ['core', 'communication'],
            permissions: [
                new PermissionDefinition('email_marketing.lists.view', 'email_marketing_lists'),
                new PermissionDefinition('email_marketing.lists.manage', 'email_marketing_lists'),
                new PermissionDefinition('email_marketing.subscribers.view', 'email_marketing_subscribers'),
                new PermissionDefinition('email_marketing.subscribers.manage', 'email_marketing_subscribers', sensitive: true),
                new PermissionDefinition('email_marketing.subscribers.import', 'email_marketing_subscribers', sensitive: true),
                new PermissionDefinition('email_marketing.subscribers.export', 'email_marketing_subscribers', sensitive: true),
                new PermissionDefinition('email_marketing.campaigns.view', 'email_marketing_campaigns', scopable: true),
                new PermissionDefinition('email_marketing.campaigns.create', 'email_marketing_campaigns'),
                new PermissionDefinition('email_marketing.campaigns.edit', 'email_marketing_campaigns', scopable: true),
                new PermissionDefinition('email_marketing.campaigns.delete', 'email_marketing_campaigns', scopable: true),
                new PermissionDefinition('email_marketing.campaigns.send', 'email_marketing_campaigns', sensitive: true),
                new PermissionDefinition('email_marketing.templates.view', 'email_marketing_templates'),
                new PermissionDefinition('email_marketing.templates.manage', 'email_marketing_templates'),
                new PermissionDefinition('email_marketing.sequences.view', 'email_marketing_sequences'),
                new PermissionDefinition('email_marketing.sequences.manage', 'email_marketing_sequences'),
                new PermissionDefinition('email_marketing.sequences.activate', 'email_marketing_sequences', sensitive: true),
                new PermissionDefinition('email_marketing.reports.view', 'email_marketing_reports'),
                new PermissionDefinition('email_marketing.settings.manage', 'email_marketing_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('emailMarketingCampaigns', 'email_marketing.campaigns.index', 'mail-open', 'email_marketing.campaigns.view', 'apps', 120, 'email_marketing.campaigns.*'),
                new NavigationItem('emailMarketingLists', 'email_marketing.lists.index', 'list-checks', 'email_marketing.lists.view', 'apps', 121, 'email_marketing.lists.*'),
                new NavigationItem('emailMarketingSubscribers', 'email_marketing.subscribers.index', 'users-round', 'email_marketing.subscribers.view', 'apps', 122, 'email_marketing.subscribers.*'),
                new NavigationItem('emailMarketingTemplates', 'email_marketing.templates.index', 'layout-template', 'email_marketing.templates.view', 'apps', 123, 'email_marketing.templates.*'),
                new NavigationItem('emailMarketingSequences', 'email_marketing.sequences.index', 'workflow', 'email_marketing.sequences.view', 'apps', 124, 'email_marketing.sequences.*'),
                new NavigationItem('emailMarketingReports', 'email_marketing.reports.index', 'chart-no-axes-combined', 'email_marketing.reports.view', 'apps', 125, 'email_marketing.reports.*'),
                new NavigationItem('emailMarketingImports', 'email_marketing.imports.index', 'upload', 'email_marketing.subscribers.import', 'apps', 126, 'email_marketing.imports.*'),
                new NavigationItem('emailMarketingSettings', 'email_marketing.settings.index', 'settings', 'email_marketing.settings.manage', 'manage', 41, 'email_marketing.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('email_marketing.overview', 'EmailMarketingOverviewWidget', EmailMarketingOverviewWidget::class, 'email_marketing.campaigns.view', span: 4, order: 22),
            ],
            triggers: [
                CampaignSentTrigger::class,
                SubscriberSubscribedTrigger::class,
            ],
            actions: [AddToListAction::class],
            morphMap: [
                'email_marketing_list' => MailingList::class,
                'email_marketing_subscription' => Subscription::class,
                'email_marketing_template' => Template::class,
                'email_marketing_segment' => Segment::class,
                'email_marketing_campaign' => Campaign::class,
                'email_marketing_delivery' => Delivery::class,
                'email_marketing_sequence' => Sequence::class,
                'email_marketing_sequence_step' => SequenceStep::class,
                'email_marketing_enrollment' => Enrollment::class,
                'email_marketing_import' => ImportRun::class,
                'email_marketing_setting' => Setting::class,
                'email_marketing_automation_receipt' => AutomationActionReceipt::class,
            ],
            limits: [
                new LimitDefinition(CampaignManager::LIMIT),
                new LimitDefinition(
                    SubscriberManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => SubscriberManager::activeCount(),
                ),
            ],
            description: 'Build email campaigns and welcome sequences for your contact lists, with consent and unsubscribe handled by the platform.',
            category: AppCategory::EmailMarketing,
            icon: 'mail-open',
            features: [
                'Lists, segments and CSV import with consent evidence',
                'Block editor with personalisation variables',
                'Scheduled campaigns and A/B subject tests',
                'Welcome and drip sequences',
                'Consent, suppression and unsubscribe enforced by the platform',
                'Delivery reports with clicks and bounces',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Initial Email Marketing release. Emails are sent as plain text through the platform mailer; opens are not measured.',
            routes: [
                'email_marketing.campaigns.index',
                'email_marketing.campaigns.create',
                'email_marketing.campaigns.store',
                'email_marketing.content.preview',
                'email_marketing.campaigns.show',
                'email_marketing.campaigns.edit',
                'email_marketing.campaigns.update',
                'email_marketing.campaigns.destroy',
                'email_marketing.campaigns.duplicate',
                'email_marketing.campaigns.export',
                'email_marketing.campaigns.schedule',
                'email_marketing.campaigns.unschedule',
                'email_marketing.campaigns.pause',
                'email_marketing.campaigns.resume',
                'email_marketing.campaigns.cancel',
                'email_marketing.campaigns.test',
                'email_marketing.templates.index',
                'email_marketing.templates.create',
                'email_marketing.templates.store',
                'email_marketing.templates.edit',
                'email_marketing.templates.update',
                'email_marketing.templates.destroy',
                'email_marketing.lists.index',
                'email_marketing.lists.show',
                'email_marketing.lists.store',
                'email_marketing.lists.update',
                'email_marketing.lists.destroy',
                'email_marketing.lists.export',
                'email_marketing.segments.store',
                'email_marketing.segments.update',
                'email_marketing.segments.destroy',
                'email_marketing.subscribers.index',
                'email_marketing.subscribers.contacts',
                'email_marketing.subscribers.store',
                'email_marketing.subscriptions.update',
                'email_marketing.subscriptions.unsubscribe',
                'email_marketing.subscriptions.destroy',
                'email_marketing.imports.index',
                'email_marketing.imports.template',
                'email_marketing.imports.store',
                'email_marketing.sequences.index',
                'email_marketing.sequences.create',
                'email_marketing.sequences.store',
                'email_marketing.sequences.show',
                'email_marketing.sequences.edit',
                'email_marketing.sequences.update',
                'email_marketing.sequences.destroy',
                'email_marketing.sequences.activate',
                'email_marketing.sequences.pause',
                'email_marketing.reports.index',
                'email_marketing.settings.index',
                'email_marketing.settings.update',
                'email_marketing.track.click',
                'email_marketing.web.show',
                'email_marketing.lists.signup',
                'email_marketing.signup.show',
                'email_marketing.signup.store',
                'email_marketing.signup.confirm.show',
                'email_marketing.signup.confirm.store',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('email_marketing.run', RunEmailMarketing::class, ScheduledJobFrequency::EveryMinute),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'email_marketing');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
        $this->loadTenantCallbackRoutes(__DIR__.'/callbacks.php');

        // What the platform tells us: the outcome of our messages, and a contact withdrawing consent.
        Event::listen(MarketingMessageStatusChanged::class, [MirrorMessageStatus::class, 'handle']);
        Event::listen(MirrorUnsubscribe::EVENT_CLASS, [MirrorUnsubscribe::class, 'handle']);
        Event::listen(ContactDeleted::class, [ForgetDeletedContact::class, 'handle']);
    }
}
