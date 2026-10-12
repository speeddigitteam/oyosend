<?php

declare(strict_types=1);

namespace App\Modules\Communication;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Contracts\UnsubscribeLinks as UnsubscribeLinksContract;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Modules\Communication\Actions\CommunicationContactMergeReference;
use App\Modules\Communication\Actions\MarketingAudienceResolver;
use App\Modules\Communication\Actions\MarketingGateway;
use App\Modules\Communication\Actions\MarketingStatusRelay;
use App\Modules\Communication\Actions\MarketingUnsubscribeLinks;
use App\Modules\Communication\Actions\NotificationCenter;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Actions\TemplateVariables;
use App\Modules\Communication\Actions\TimelineRecorder;
use App\Modules\Communication\Automation\Actions\LogInteractionAction;
use App\Modules\Communication\Automation\Actions\SendTemplateMessageAction;
use App\Modules\Communication\Automation\Triggers\ContactUnsubscribedTrigger;
use App\Modules\Communication\Automation\Triggers\MessageBouncedTrigger;
use App\Modules\Communication\Automation\Triggers\MessageDeliveredTrigger;
use App\Modules\Communication\Automation\Triggers\MessageFailedTrigger;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Channels\Providers\LaravelMailProvider;
use App\Modules\Communication\Channels\Providers\NullProvider;
use App\Modules\Communication\Channels\Providers\WhatsAppCloudProvider;
use App\Modules\Communication\Events\MessageBounced;
use App\Modules\Communication\Events\MessageDelivered;
use App\Modules\Communication\Events\MessageFailed;
use App\Modules\Communication\Events\MessageSent;
use App\Modules\Communication\Jobs\CleanupCommunication;
use App\Modules\Communication\Jobs\DispatchDueMessages;
use App\Modules\Communication\Models\AutomationActionReceipt;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\CommunicationSetting;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\MemberNotification;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Models\NotificationPreference;
use App\Modules\Communication\Models\Suppression;
use App\Modules\Communication\Models\UnsubscribeToken;
use App\Modules\Communication\Models\WebhookEvent;
use App\Modules\Communication\Testing\InMemoryProvider;
use App\Modules\Communication\Widgets\NotificationsWidget;
use Illuminate\Support\Facades\Event;

/**
 * The communication platform: the notification center, the message outbox with provider adapters, templates,
 * consent and suppression, and the interaction timeline.
 *
 * It owns the implementations of two core contracts, {@see Notifier} and {@see InteractionRecorder}, so other
 * modules notify people and append to timelines without importing a model from here.
 */
final class CommunicationServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(TemplateVariables::class);

        $this->app->singleton(ProviderRegistry::class, static function (): ProviderRegistry {
            $registry = new ProviderRegistry;
            $registry->register(new LaravelMailProvider);
            // The honest default for SMS and WhatsApp: never configured, refuses every send.
            $registry->register(NullProvider::sms());
            $registry->register(NullProvider::whatsapp());
            // The official WhatsApp Cloud API: not_configured until the operator provides credentials.
            $registry->register(new WhatsAppCloudProvider);

            // In-memory doubles exist only for tests and local development, and say so.
            if (app()->environment(['testing', 'local'])) {
                $registry->register(InMemoryProvider::sms());
                $registry->register(InMemoryProvider::whatsapp());
            }

            return $registry;
        });

        $this->app->bind(Notifier::class, NotificationCenter::class);
        $this->app->bind(InteractionRecorder::class, TimelineRecorder::class);

        // Contracts for the marketing apps (email, SMS, WhatsApp): no model import needed on their side.
        $this->app->bind(MarketingAudience::class, MarketingAudienceResolver::class);
        $this->app->bind(UnsubscribeLinksContract::class, MarketingUnsubscribeLinks::class);
        $this->app->bind(ChannelGateway::class, MarketingGateway::class);
        $this->app->tag(CommunicationContactMergeReference::class, ContactMergeReference::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'communication',
            name: 'Communication',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('communication.notifications.view', 'communication_notifications'),
                new PermissionDefinition('communication.messages.view', 'communication_messages', scopable: true),
                new PermissionDefinition('communication.messages.send', 'communication_messages', scopable: true),
                new PermissionDefinition('communication.timeline.view', 'communication_timeline'),
                new PermissionDefinition('communication.timeline.log', 'communication_timeline', scopable: true),
                new PermissionDefinition('communication.templates.view', 'communication_templates'),
                new PermissionDefinition('communication.templates.manage', 'communication_templates'),
                new PermissionDefinition('communication.audience.view', 'communication_audience'),
                new PermissionDefinition('communication.consent.manage', 'communication_audience', sensitive: true),
                new PermissionDefinition('communication.suppressions.manage', 'communication_audience', sensitive: true),
                new PermissionDefinition('communication.settings.manage', 'communication_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('communicationNotifications', 'communication.notifications.index', 'bell', 'communication.notifications.view', 'apps', 30, 'communication.notifications.*'),
                new NavigationItem('communicationMessages', 'communication.messages.index', 'mail', 'communication.messages.view', 'apps', 31, 'communication.messages.*'),
                new NavigationItem('communicationTemplates', 'communication.templates.index', 'file-text', 'communication.templates.view', 'apps', 32, 'communication.templates.*'),
                new NavigationItem('communicationAudience', 'communication.audience.index', 'shield-check', 'communication.audience.view', 'apps', 33, 'communication.audience.*'),
                new NavigationItem('communicationSettings', 'communication.settings.index', 'settings', 'communication.settings.manage', 'manage', 39, 'communication.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('communication.notifications', 'NotificationsWidget', NotificationsWidget::class, 'communication.notifications.view', span: 4, order: 60),
            ],
            triggers: [
                MessageDeliveredTrigger::class,
                MessageFailedTrigger::class,
                MessageBouncedTrigger::class,
                ContactUnsubscribedTrigger::class,
            ],
            actions: [SendTemplateMessageAction::class, LogInteractionAction::class],
            morphMap: [
                'communication_notification' => MemberNotification::class,
                'communication_preference' => NotificationPreference::class,
                'communication_setting' => CommunicationSetting::class,
                'communication_channel' => ChannelConnection::class,
                'communication_template' => MessageTemplate::class,
                'communication_message' => Message::class,
                'communication_interaction' => Interaction::class,
                'communication_suppression' => Suppression::class,
                'communication_consent' => Consent::class,
                'communication_unsubscribe_token' => UnsubscribeToken::class,
                'communication_webhook_event' => WebhookEvent::class,
                'communication_action_receipt' => AutomationActionReceipt::class,
            ],
            limits: [new LimitDefinition(Outbox::LIMIT)],
            description: 'Notify your team, send email to customers with consent, and keep every interaction in one timeline.',
            category: AppCategory::CustomerSupport,
            icon: 'mail',
            features: [
                'Notification center with per-category preferences',
                'Email with templates in English and Bengali',
                'Consent, suppression list and one-click unsubscribe',
                'Interaction timeline for every customer',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial communication platform release.',
            routes: [
                'communication.notifications.index',
                'communication.notifications.summary',
                'communication.notifications.read-all',
                'communication.notifications.read',
                'communication.notifications.unread',
                'communication.notifications.archive',
                'communication.preferences.index',
                'communication.preferences.update',
                'communication.messages.index',
                'communication.messages.create',
                'communication.messages.store',
                'communication.messages.show',
                'communication.messages.retry',
                'communication.messages.cancel',
                'communication.timeline.contact.show',
                'communication.timeline.company.show',
                'communication.timeline.contact.store',
                'communication.timeline.company.store',
                'communication.timeline.destroy',
                'communication.templates.index',
                'communication.templates.preview',
                'communication.templates.store',
                'communication.templates.update',
                'communication.templates.destroy',
                'communication.audience.index',
                'communication.consent.store',
                'communication.suppressions.store',
                'communication.suppressions.destroy',
                'communication.settings.index',
                'communication.settings.update',
                'communication.settings.channels.update',
                'communication.webhooks.receive',
                'communication.unsubscribe.show',
                'communication.unsubscribe.store',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('communication.dispatch_due', DispatchDueMessages::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('communication.cleanup', CleanupCommunication::class, ScheduledJobFrequency::Daily),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
        $this->loadTenantCallbackRoutes(__DIR__.'/callbacks.php');

        // Marketing apps hear about their messages through the core MarketingMessageStatusChanged event.
        foreach ([MessageSent::class, MessageDelivered::class, MessageFailed::class, MessageBounced::class] as $event) {
            Event::listen($event, [MarketingStatusRelay::class, 'handle']);
        }
    }
}
