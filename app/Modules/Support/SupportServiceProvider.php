<?php

declare(strict_types=1);

namespace App\Modules\Support;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Communication\Contracts\InboxSource;
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
use App\Modules\Support\Actions\InboundTicketHandler;
use App\Modules\Support\Actions\SupportCatalogue;
use App\Modules\Support\Actions\SupportContactMergeReference;
use App\Modules\Support\Actions\SurveyManager;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Automation\Actions\AddInternalNoteAction;
use App\Modules\Support\Automation\Actions\AssignTicketAction;
use App\Modules\Support\Automation\Actions\CreateTicketAction;
use App\Modules\Support\Automation\Triggers\CsatSubmittedTrigger;
use App\Modules\Support\Automation\Triggers\TicketAssignedTrigger;
use App\Modules\Support\Automation\Triggers\TicketCreatedTrigger;
use App\Modules\Support\Automation\Triggers\TicketCustomerRepliedTrigger;
use App\Modules\Support\Automation\Triggers\TicketResolvedTrigger;
use App\Modules\Support\Automation\Triggers\TicketSlaBreachedTrigger;
use App\Modules\Support\Contracts\InboundTicketReceiver;
use App\Modules\Support\CustomerRecords\TicketsSection;
use App\Modules\Support\Inbox\LiveChatInboxSource;
use App\Modules\Support\Inbox\TicketInboxSource;
use App\Modules\Support\Jobs\MonitorSla;
use App\Modules\Support\Jobs\RunMaintenance;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\AssignmentRule;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\AutomationActionReceipt;
use App\Modules\Support\Models\Calendar;
use App\Modules\Support\Models\CannedResponse;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Models\ChatSetting;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbArticleVersion;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\NumberSequence;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\Setting;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Models\Tag;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketTag;
use App\Modules\Support\Reporting\ReportProviders;
use App\Modules\Support\Support\ChatAccess;
use App\Modules\Support\Support\LiveChatLimits;
use App\Modules\Support\Support\TicketAccess;
use App\Modules\Support\Widgets\SupportOverviewWidget;

/**
 * Customer Support (category Customer Support): tickets with queues and assignment, conversation threads
 * with internal notes, canned responses, SLA policies on business-hours calendars, satisfaction surveys,
 * a knowledge base, merge and split, reports, and a website live chat widget (ADR 0032).
 *
 * Customers are the shared contacts and companies of the workspace; the app has no customer table. It
 * depends on nothing but `core`. Channel integrations hand messages to `Contracts\InboundTicketReceiver`.
 */
final class SupportServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        $this->app->scoped(TicketAccess::class);
        $this->app->scoped(SurveyManager::class);
        $this->app->bind(InboundTicketReceiver::class, InboundTicketHandler::class);
        $this->app->tag(SupportContactMergeReference::class, ContactMergeReference::class);
        $this->app->tag(TicketsSection::class, CustomerRecordProvider::TAG);
        $this->app->tag(TicketInboxSource::class, InboxSource::TAG);
        $this->app->scoped(ChatAccess::class);
        $this->app->tag(LiveChatInboxSource::class, InboxSource::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'support',
            name: 'Customer Support',
            version: '1.2.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('support.tickets.view', 'support_tickets', scopable: true),
                new PermissionDefinition('support.tickets.create', 'support_tickets'),
                new PermissionDefinition('support.tickets.reply', 'support_tickets', scopable: true),
                new PermissionDefinition('support.tickets.edit', 'support_tickets', scopable: true),
                new PermissionDefinition('support.tickets.assign', 'support_tickets', scopable: true),
                new PermissionDefinition('support.tickets.resolve', 'support_tickets', scopable: true),
                new PermissionDefinition('support.tickets.merge', 'support_tickets', scopable: true, sensitive: true),
                new PermissionDefinition('support.canned.view', 'support_knowledge'),
                new PermissionDefinition('support.canned.manage', 'support_knowledge'),
                new PermissionDefinition('support.kb.view', 'support_knowledge'),
                new PermissionDefinition('support.kb.manage', 'support_knowledge'),
                new PermissionDefinition('support.kb.publish', 'support_knowledge'),
                new PermissionDefinition('support.queues.manage', 'support_configuration', sensitive: true),
                new PermissionDefinition('support.sla.manage', 'support_configuration', sensitive: true),
                new PermissionDefinition('support.settings.manage', 'support_configuration', sensitive: true),
                new PermissionDefinition('support.reports.view', 'support_reports', sensitive: true),
            ],
            navigation: [
                new NavigationItem('supportTickets', 'support.tickets.index', 'life-buoy', 'support.tickets.view', 'apps', 60, 'support.tickets.*'),
                new NavigationItem('supportLiveChat', 'support.live_chat.index', 'message-circle', 'support.tickets.view', 'apps', 60, 'support.live_chat.*'),
                new NavigationItem('supportKb', 'support.kb.index', 'book-open', 'support.kb.view', 'apps', 61, 'support.kb.*'),
                new NavigationItem('supportCanned', 'support.canned.index', 'message-square-text', 'support.canned.view', 'apps', 62, 'support.canned.*'),
                new NavigationItem('supportReports', 'support.reports.index', 'chart-no-axes-combined', 'support.reports.view', 'apps', 63, 'support.reports.*'),
                new NavigationItem('supportQueues', 'support.queues.index', 'inbox', 'support.queues.manage', 'apps', 900, 'support.queues.*'),
                new NavigationItem('supportSla', 'support.sla.index', 'timer', 'support.sla.manage', 'apps', 901, 'support.sla.*'),
                new NavigationItem('supportSettings', 'support.settings.index', 'settings', 'support.settings.manage', 'apps', 902, 'support.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('support.overview', 'SupportOverviewWidget', SupportOverviewWidget::class, 'support.tickets.view', span: 4, order: 19),
            ],
            triggers: [
                TicketCreatedTrigger::class,
                TicketAssignedTrigger::class,
                TicketCustomerRepliedTrigger::class,
                TicketResolvedTrigger::class,
                TicketSlaBreachedTrigger::class,
                CsatSubmittedTrigger::class,
            ],
            actions: [CreateTicketAction::class, AssignTicketAction::class, AddInternalNoteAction::class],
            morphMap: [
                'support_setting' => Setting::class,
                'support_number_sequence' => NumberSequence::class,
                'support_calendar' => Calendar::class,
                'support_sla_policy' => SlaPolicy::class,
                'support_queue' => Queue::class,
                'support_queue_agent' => QueueAgent::class,
                'support_category' => Category::class,
                'support_agent' => Agent::class,
                'support_assignment_rule' => AssignmentRule::class,
                'support_tag' => Tag::class,
                'support_ticket' => Ticket::class,
                'support_ticket_tag' => TicketTag::class,
                'support_message' => Message::class,
                'support_attachment' => Attachment::class,
                'support_ticket_event' => TicketEvent::class,
                'support_canned_response' => CannedResponse::class,
                'support_csat_survey' => CsatSurvey::class,
                'support_kb_category' => KbCategory::class,
                'support_kb_article' => KbArticle::class,
                'support_kb_article_version' => KbArticleVersion::class,
                'support_automation_receipt' => AutomationActionReceipt::class,
                'support_chat_setting' => ChatSetting::class,
                'support_chat_conversation' => ChatConversation::class,
                'support_chat_message' => ChatMessage::class,
            ],
            limits: [
                new LimitDefinition(TicketManager::LIMIT),
                new LimitDefinition(
                    SupportCatalogue::AGENT_LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => Agent::query()->where('is_active', true)->count(),
                ),
            ],
            description: 'Customer tickets with queues, assignment rules and SLA targets, a conversation thread with internal notes, canned responses, satisfaction surveys and an internal knowledge base.',
            category: AppCategory::CustomerSupport,
            icon: 'life-buoy',
            featured: false,
            features: [
                'Tickets linked to shared contacts and companies, with gap-free numbers',
                'Queues, agents, round-robin and rule-based assignment',
                'Public replies, internal notes and private attachments',
                'SLA targets on business-hours calendars with pause while waiting',
                'Satisfaction surveys with single-use links',
                'Knowledge base with versions, merge and split, and support reports',
                'Website live chat widget answered from the Live chat page and the unified inbox',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: '1.2.0: from a website chat, a member can save the visitor as a shared contact (or link the existing one with the same email address) and turn the chat into a ticket with the transcript as an internal note. 1.1.0: website live chat. A chat widget for the workspace website (allowed origins, greeting, visitor details), a Live chat page, and the chats in the unified inbox with replies. 1.0.0: initial Customer Support release.',
            routes: [
                'support.tickets.index',
                'support.tickets.create',
                'support.tickets.store',
                'support.tickets.show',
                'support.tickets.update',
                'support.tickets.attachments.download',
                'support.tickets.canned.preview',
                'support.tickets.messages.store',
                'support.tickets.status',
                'support.tickets.assign',
                'support.tickets.claim',
                'support.tickets.merge',
                'support.tickets.split',
                'support.tickets.survey',
                'support.canned.index',
                'support.canned.store',
                'support.canned.update',
                'support.canned.destroy',
                'support.kb.index',
                'support.kb.articles.show',
                'support.kb.articles.store',
                'support.kb.articles.update',
                'support.kb.articles.restore',
                'support.kb.articles.publish',
                'support.kb.articles.unpublish',
                'support.kb.articles.archive',
                'support.kb.categories.store',
                'support.kb.categories.update',
                'support.kb.categories.destroy',
                'support.reports.index',
                'support.queues.index',
                'support.queues.store',
                'support.queues.update',
                'support.agents.store',
                'support.agents.update',
                'support.categories.store',
                'support.categories.update',
                'support.rules.store',
                'support.rules.update',
                'support.rules.destroy',
                'support.sla.index',
                'support.sla.policies.store',
                'support.sla.policies.update',
                'support.sla.calendars.store',
                'support.sla.calendars.update',
                'support.settings.index',
                'support.settings.update',
                'support.csat.show',
                'support.csat.store',
                'support.live_chat.index',
                'support.live_chat.reply',
                'support.live_chat.close',
                'support.live_chat.contact',
                'support.live_chat.ticket',
                'support.live_chat.settings',
                'support.live_chat.settings.update',
                'support.live_chat.widget',
                'support.live_chat.frame',
                'support.live_chat.api.start',
                'support.live_chat.api.send',
                'support.live_chat.api.poll',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('support.sla', MonitorSla::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('support.maintenance', RunMaintenance::class, ScheduledJobFrequency::Daily),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
        $this->loadTenantCallbackRoutes(__DIR__.'/callbacks.php');
        LiveChatLimits::register();
    }
}
