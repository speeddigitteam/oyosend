<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing;

use App\Core\Ai\Contracts\AiTool;
use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
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
use App\Modules\WhatsappMarketing\Ai\ConversationReadTool;
use App\Modules\WhatsappMarketing\Automation\Actions\AssignConversationAction;
use App\Modules\WhatsappMarketing\Automation\Actions\SendTemplateAction;
use App\Modules\WhatsappMarketing\Automation\Actions\TagConversationAction;
use App\Modules\WhatsappMarketing\Automation\Triggers\CampaignCompletedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\ConversationAssignedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\FeedbackReceivedTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\MessageReadTrigger;
use App\Modules\WhatsappMarketing\Automation\Triggers\MessageReceivedTrigger;
use App\Modules\WhatsappMarketing\Crm\CaptureLeadFromConversation;
use App\Modules\WhatsappMarketing\CustomerRecords\ConversationsSection;
use App\Modules\WhatsappMarketing\Events\MessageReceived;
use App\Modules\WhatsappMarketing\Inbox\ConversationInboxSource;
use App\Modules\WhatsappMarketing\Jobs\RunCampaigns;
use App\Modules\WhatsappMarketing\Jobs\RunMaintenance;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\AutomationActionReceipt;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\CapturedLead;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ConversationNote;
use App\Modules\WhatsappMarketing\Models\Feedback;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\LeadCaptureSetting;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Setting;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Models\Window;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendAppointmentReminderAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendInvoiceNotificationAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendOrderConfirmationAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendPaymentReminderAction;
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
        $this->app->tag(ConversationsSection::class, CustomerRecordProvider::TAG);
        $this->app->tag(ConversationInboxSource::class, InboxSource::TAG);
        $this->app->tag(ConversationReadTool::class, AiTool::TAG);
        $this->app->scoped(ConversationAccess::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'whatsapp_marketing',
            name: 'WhatsApp Business',
            version: '1.2.0',
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
                new NavigationItem('whatsappCrm', 'whatsapp_marketing.crm.index', 'user-plus', 'whatsapp_marketing.conversations.view', 'apps', 76, 'whatsapp_marketing.crm.*'),
                new NavigationItem('whatsappFlows', 'whatsapp_marketing.flows.index', 'workflow', 'whatsapp_marketing.flows.manage', 'apps', 900, 'whatsapp_marketing.flows.*'),
                new NavigationItem('whatsappSettings', 'whatsapp_marketing.settings.index', 'settings', 'whatsapp_marketing.settings.manage', 'apps', 901, 'whatsapp_marketing.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('whatsapp_marketing.overview', 'WhatsappOverviewWidget', WhatsappOverviewWidget::class, 'whatsapp_marketing.conversations.view', span: 4, order: 21),
            ],
            triggers: [
                MessageReceivedTrigger::class,
                MessageReadTrigger::class,
                ConversationAssignedTrigger::class,
                CampaignCompletedTrigger::class,
                FeedbackReceivedTrigger::class,
            ],
            actions: [
                SendTemplateAction::class, AssignConversationAction::class, TagConversationAction::class,
                SendOrderConfirmationAction::class, SendInvoiceNotificationAction::class,
                SendAppointmentReminderAction::class, SendPaymentReminderAction::class,
            ],
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
                'whatsapp_conversation_note' => ConversationNote::class,
                'whatsapp_feedback' => Feedback::class,
                'whatsapp_lead_capture_setting' => LeadCaptureSetting::class,
                'whatsapp_captured_lead' => CapturedLead::class,
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
                'Shared inbox with assignment, tags, internal notes and the 24-hour customer-service window',
                'Reply buttons and list messages, from agents and from chatbot menus',
                'Quick replies and declarative chatbot flows',
                'Customer ratings after a conversation is closed (optional)',
                'Lists filled from contact rules',
                'Broadcast campaigns to a CRM segment, scoped to what you can see',
                'Capture inbound conversations as CRM leads, without duplicating contacts',
                'Transactional reminder and notification templates, manually or from workflows',
                'Several business numbers, with a sending-number picker in the inbox and campaigns',
                'Interactive replies: buttons, lists and call-to-action links, inside the 24-hour window',
                'Send and receive media (image, document, video, audio); inbound files served securely',
                'Template messages with a media header and dynamic button values',
                'Delivery and read status, conversation counts and reports',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Adds a CRM bridge: broadcast a campaign to a CRM segment (scoped to the launcher), capture inbound conversations as CRM leads through the lead-intake contract, and transactional reminder/notification templates manually or from workflows. Adds the WhatsApp platform feature layer (ADR 0022): several business numbers with a sending-number picker, interactive replies (buttons, lists, call-to-action links) and media messages inside the 24-hour window, secure serving of inbound media, and templates with a media header and button values. The WhatsApp Cloud API adapter has still not been exercised against live Meta.',
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
                'whatsapp_marketing.inbox.notes.store',
                'whatsapp_marketing.inbox.notes.destroy',
                'whatsapp_marketing.inbox.interactive',
                'whatsapp_marketing.inbox.cta',
                'whatsapp_marketing.inbox.media',
                'whatsapp_marketing.inbox.rich-template',
                'whatsapp_marketing.messages.media',
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
                'whatsapp_marketing.lists.members.rules',
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
                'whatsapp_marketing.campaigns.segments',
                'whatsapp_marketing.campaigns.from_segment',
                'whatsapp_marketing.crm.index',
                'whatsapp_marketing.crm.contacts',
                'whatsapp_marketing.crm.reminders',
                'whatsapp_marketing.crm.lead_capture',
                'whatsapp_marketing.flows.index',
                'whatsapp_marketing.flows.store',
                'whatsapp_marketing.flows.update',
                'whatsapp_marketing.flows.destroy',
                'whatsapp_marketing.analytics.index',
                'whatsapp_marketing.settings.index',
                'whatsapp_marketing.settings.update',
                'whatsapp_marketing.agents.store',
                'whatsapp_marketing.agents.update',
                'whatsapp_marketing.numbers.index',
                'whatsapp_marketing.numbers.store',
                'whatsapp_marketing.numbers.update',
                'whatsapp_marketing.numbers.default',
                'whatsapp_marketing.numbers.destroy',
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

        // Turn inbound conversations into CRM leads (off by default) without touching the inbound handler.
        Event::listen(MessageReceived::class, [CaptureLeadFromConversation::class, 'handle']);
    }
}
