<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Communication\Contracts\ChannelEventListener;
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
use App\Modules\WhatsappMarketing\Actions\AgentManager;
use App\Modules\WhatsappMarketing\Actions\ChannelEventHandler;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Actions\StatusSync;
use App\Modules\WhatsappMarketing\Actions\WhatsappContactMergeReference;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Automation\Actions\AssignConversationAction;
use App\Modules\WhatsappMarketing\Automation\Actions\SendTemplateAction;
use App\Modules\WhatsappMarketing\Automation\Actions\TagConversationAction;
use App\Modules\WhatsappMarketing\Automation\Triggers\CampaignCompletedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\ConversationAssignedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\MessageReadTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\MessageReceivedTrigger;
use App\Modules\WhatsappMarketing\Jobs\RunCampaigns;
use App\Modules\WhatsappMarketing\Jobs\RunMaintenance;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\AutomationActionReceipt;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Setting;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Models\Window;
use App\Modules\WhatsappMarketing\Reporting\ReportProviders;
use App\Modules\WhatsappMarketing\Widgets\WhatsappOverviewWidget;
use Illuminate\Support\Facades\Event;

/**
 * WhatsApp Business (category WhatsApp marketing): approved-template campaigns to lists of shared contacts, a
 * shared inbox with the 24-hour customer-service window, agent assignment, quick replies and declarative chatbot
 * flows.
 *
 * It depends on `core` and `communication` and imports nothing else: messages go out through the core
 * `ChannelGateway`, consent comes from the core `MarketingAudience`, and provider events (inbound messages, read
 * receipts, template status) arrive through the core `ChannelEventListener`. There is no credential and no
 * provider connection in this app: that is a channel of the communication app.
 */
final class WhatsappMarketingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
        $this->app->tag(ChannelEventHandler::class, ChannelEventListener::TAG);
        $this->app->tag(WhatsappContactMergeReference::class, ContactMergeReference::class);
        $this->app->scoped(ConversationAccess::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'whatsapp_marketing',
            name: 'WhatsApp Business',
            version: '1.0.0',
            dependencies: ['core', 'communication'],
            permissions: [
                new PermissionDefinition('whatsapp_marketing.conversations.view', 'whatsapp_conversations', scopable: true),
                new PermissionDefinition('whatsapp_marketing.conversations.reply', 'whatsapp_conversations', scopable: true),
                new PermissionDefinition('whatsapp_marketing.conversations.manage', 'whatsapp_conversations', scopable: true),
                new PermissionDefinition('whatsapp_marketing.conversations.assign', 'whatsapp_conversations', scopable: true),
                new PermissionDefinition('whatsapp_marketing.templates.view', 'whatsapp_templates'),
                new PermissionDefinition('whatsapp_marketing.templates.manage', 'whatsapp_templates'),
                new PermissionDefinition('whatsapp_marketing.campaigns.view', 'whatsapp_campaigns', scopable: true),
                new PermissionDefinition('whatsapp_marketing.campaigns.manage', 'whatsapp_campaigns', scopable: true),
                new PermissionDefinition('whatsapp_marketing.campaigns.send', 'whatsapp_campaigns', sensitive: true),
                new PermissionDefinition('whatsapp_marketing.lists.view', 'whatsapp_lists'),
                new PermissionDefinition('whatsapp_marketing.lists.manage', 'whatsapp_lists'),
                new PermissionDefinition('whatsapp_marketing.quick_replies.view', 'whatsapp_quick_replies'),
                new PermissionDefinition('whatsapp_marketing.quick_replies.manage', 'whatsapp_quick_replies'),
                new PermissionDefinition('whatsapp_marketing.flows.manage', 'whatsapp_configuration', sensitive: true),
                new PermissionDefinition('whatsapp_marketing.analytics.view', 'whatsapp_analytics'),
                new PermissionDefinition('whatsapp_marketing.settings.manage', 'whatsapp_configuration', sensitive: true),
            ],
            navigation: [
                new NavigationItem('whatsappInbox', 'whatsapp_marketing.inbox.index', 'message-circle', 'whatsapp_marketing.conversations.view', 'apps', 70, 'whatsapp_marketing.inbox.*'),
                new NavigationItem('whatsappCampaigns', 'whatsapp_marketing.campaigns.index', 'megaphone', 'whatsapp_marketing.campaigns.view', 'apps', 71, 'whatsapp_marketing.campaigns.*'),
                new NavigationItem('whatsappTemplates', 'whatsapp_marketing.templates.index', 'file-text', 'whatsapp_marketing.templates.view', 'apps', 72, 'whatsapp_marketing.templates.*'),
                new NavigationItem('whatsappLists', 'whatsapp_marketing.lists.index', 'users-round', 'whatsapp_marketing.lists.view', 'apps', 73, 'whatsapp_marketing.lists.*'),
                new NavigationItem('whatsappQuickReplies', 'whatsapp_marketing.quick-replies.index', 'zap', 'whatsapp_marketing.quick_replies.view', 'apps', 74, 'whatsapp_marketing.quick-replies.*'),
                new NavigationItem('whatsappAnalytics', 'whatsapp_marketing.analytics.index', 'chart-no-axes-combined', 'whatsapp_marketing.analytics.view', 'apps', 75, 'whatsapp_marketing.analytics.*'),
                new NavigationItem('whatsappFlows', 'whatsapp_marketing.flows.index', 'workflow', 'whatsapp_marketing.flows.manage', 'manage', 70, 'whatsapp_marketing.flows.*'),
                new NavigationItem('whatsappSettings', 'whatsapp_marketing.settings.index', 'settings', 'whatsapp_marketing.settings.manage', 'manage', 71, 'whatsapp_marketing.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('whatsapp_marketing.overview', 'WhatsappOverviewWidget', WhatsappOverviewWidget::class, 'whatsapp_marketing.conversations.view', span: 4, order: 21),
            ],
            triggers: [
                MessageReceivedTrigger::class,
                MessageReadTrigger::class,
                ConversationAssignedTrigger::class,
                CampaignCompletedTrigger::class,
            ],
            actions: [SendTemplateAction::class, AssignConversationAction::class, TagConversationAction::class],
            morphMap: [
                'whatsapp_setting' => Setting::class,
                'whatsapp_agent' => Agent::class,
                'whatsapp_template' => Template::class,
                'whatsapp_quick_reply' => QuickReply::class,
                'whatsapp_list' => ContactList::class,
                'whatsapp_list_member' => ListMember::class,
                'whatsapp_campaign' => Campaign::class,
                'whatsapp_recipient' => Recipient::class,
                'whatsapp_conversation' => Conversation::class,
                'whatsapp_message' => Message::class,
                'whatsapp_flow' => Flow::class,
                'whatsapp_window' => Window::class,
                'whatsapp_automation_receipt' => AutomationActionReceipt::class,
            ],
            limits: [
                new LimitDefinition(WindowTracker::LIMIT),
                new LimitDefinition(
                    AgentManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => Agent::query()->where('is_active', true)->count(),
                ),
            ],
            description: 'Message customers on WhatsApp through the official Business Platform: approved templates to opted-in lists, a shared inbox that respects the 24-hour window, agent assignment, quick replies and simple chatbot flows.',
            category: AppCategory::WhatsappMarketing,
            icon: 'message-circle',
            featured: false,
            features: [
                'Official WhatsApp Business Platform (Cloud API) through the communication app',
                'Approved template management with the status Meta reported',
                'Campaigns to contact lists, sent only to people who opted in',
                'Shared inbox with assignment, tags and the 24-hour customer-service window',
                'Quick replies and declarative chatbot flows',
                'Delivery and read status, conversation counts and reports',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial WhatsApp Business release. The WhatsApp Cloud API adapter has not been exercised against live Meta; it needs the operator\'s Meta app, a verified business and approved templates.',
            routes: [
                'whatsapp_marketing.inbox.index',
                'whatsapp_marketing.inbox.store',
                'whatsapp_marketing.inbox.show',
                'whatsapp_marketing.inbox.read',
                'whatsapp_marketing.inbox.reply',
                'whatsapp_marketing.inbox.template',
                'whatsapp_marketing.inbox.assign',
                'whatsapp_marketing.inbox.claim',
                'whatsapp_marketing.inbox.status',
                'whatsapp_marketing.inbox.tags',
                'whatsapp_marketing.inbox.contact',
                'whatsapp_marketing.templates.index',
                'whatsapp_marketing.templates.store',
                'whatsapp_marketing.templates.update',
                'whatsapp_marketing.templates.status',
                'whatsapp_marketing.templates.destroy',
                'whatsapp_marketing.quick-replies.index',
                'whatsapp_marketing.quick-replies.store',
                'whatsapp_marketing.quick-replies.update',
                'whatsapp_marketing.quick-replies.destroy',
                'whatsapp_marketing.lists.index',
                'whatsapp_marketing.lists.show',
                'whatsapp_marketing.lists.store',
                'whatsapp_marketing.lists.update',
                'whatsapp_marketing.lists.destroy',
                'whatsapp_marketing.lists.members.store',
                'whatsapp_marketing.lists.members.all',
                'whatsapp_marketing.lists.members.destroy',
                'whatsapp_marketing.campaigns.index',
                'whatsapp_marketing.campaigns.create',
                'whatsapp_marketing.campaigns.preview',
                'whatsapp_marketing.campaigns.store',
                'whatsapp_marketing.campaigns.show',
                'whatsapp_marketing.campaigns.edit',
                'whatsapp_marketing.campaigns.update',
                'whatsapp_marketing.campaigns.destroy',
                'whatsapp_marketing.campaigns.send',
                'whatsapp_marketing.campaigns.cancel',
                'whatsapp_marketing.flows.index',
                'whatsapp_marketing.flows.store',
                'whatsapp_marketing.flows.update',
                'whatsapp_marketing.flows.destroy',
                'whatsapp_marketing.analytics.index',
                'whatsapp_marketing.settings.index',
                'whatsapp_marketing.settings.update',
                'whatsapp_marketing.agents.store',
                'whatsapp_marketing.agents.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('whatsapp_marketing.campaigns', RunCampaigns::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('whatsapp_marketing.maintenance', RunMaintenance::class, ScheduledJobFrequency::Daily),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        // Our own messages' progress, announced by the communication platform on the core event.
        Event::listen(MarketingMessageStatusChanged::class, [StatusSync::class, 'handle']);
    }
}
