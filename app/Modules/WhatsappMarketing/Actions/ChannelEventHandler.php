<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\ChannelEventType;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\NotificationMessage;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Events\MessageRead;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hears the communication platform's verified provider events (inbound messages, read receipts, delivery
 * details, template approval changes) and keeps this app's own records in step. It checks its own entitlement:
 * the platform's webhook route only requires the communication app.
 */
final class ChannelEventHandler implements ChannelEventListener
{
    public function __construct(
        private readonly WhatsappAccess $access,
        private readonly ConversationManager $conversations,
        private readonly SettingsManager $settings,
        private readonly AutoAssigner $assigner,
        private readonly FlowEngine $flows,
        private readonly TemplateManager $templates,
        private readonly MessageLocator $locator,
        private readonly StatusProgress $progress,
        private readonly Notifier $notifier,
        private readonly FeedbackCollector $feedback,
    ) {}

    public function handle(ChannelEvent $event): void
    {
        if ($event->channel !== MarketingChannel::Whatsapp || ! $this->access->entitled()) {
            return;
        }

        match ($event->type) {
            ChannelEventType::Inbound => $this->inbound($event),
            ChannelEventType::Read => $this->read($event),
            ChannelEventType::Delivered, ChannelEventType::Failed => $this->details($event),
            ChannelEventType::TemplateStatus => $this->templateStatus($event),
            default => null,
        };
    }

    private function inbound(ChannelEvent $event): void
    {
        $result = $this->conversations->receive($event);

        if ($result === null) {
            return;
        }

        $conversation = $result->conversation;

        try {
            // A rating is answered with a thank-you; it starts no flow and wakes nobody.
            if ($result->feedback !== null) {
                $this->feedback->thank($conversation);

                return;
            }

            if ($conversation->owner_id === null && $this->settings->current()->auto_assign) {
                $member = $this->assigner->pick();

                if ($member !== null) {
                    $this->conversations->assign($conversation, $member, 'auto');
                }
            }

            if ($conversation->owner_id !== null) {
                $this->notify($conversation, $result->message->id);
            }

            $this->flows->handle($result);
        } catch (Throwable $exception) {
            // The message is already stored. A failing helper must not lose it or break the webhook.
            Log::warning('A WhatsApp inbound follow-up failed.', ['exception' => $exception::class]);
        }
    }

    private function read(ChannelEvent $event): void
    {
        if ($event->module !== Messenger::MODULE) {
            return;
        }

        ['recipient' => $recipient, 'message' => $message] = $this->locator->find($event->campaignId, $event->recipientId, $event->messageId);
        $at = $event->occurredAt ?? CarbonImmutable::now();
        $changed = false;

        if ($message !== null) {
            $changed = $this->progress->message($message, MessageStatus::Read, null, $at);
            $this->recordBilling($message, $event);
        }

        if ($recipient !== null) {
            $changed = $this->progress->recipient($recipient, RecipientState::Read, null, $at) || $changed;
        }

        if ($changed && $message !== null) {
            event(new MessageRead([
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'contact_id' => $event->contactId,
                'campaign_id' => $event->campaignId,
            ]));
        }
    }

    /** Delivery and failure events add what the provider reported about billing; state is the status relay's job. */
    private function details(ChannelEvent $event): void
    {
        if ($event->module !== Messenger::MODULE) {
            return;
        }

        $message = $this->locator->find($event->campaignId, $event->recipientId, $event->messageId)['message'];

        if ($message !== null) {
            $this->recordBilling($message, $event);
        }
    }

    private function recordBilling(Message $message, ChannelEvent $event): void
    {
        $billable = $event->details['billable'] ?? null;
        $category = $event->details['pricing_category'] ?? null;

        if ($billable === null && $category === null) {
            return;
        }

        // Only what the provider reported. A missing value stays null: "not reported".
        $message->forceFill([
            'billable' => $billable === null ? $message->billable : (bool) $billable,
            'pricing_category' => is_string($category) ? $category : $message->pricing_category,
        ])->save();
    }

    private function templateStatus(ChannelEvent $event): void
    {
        $name = (string) ($event->details['template_name'] ?? '');
        $language = (string) ($event->details['template_language'] ?? '');
        $status = (string) ($event->details['template_status'] ?? '');

        if ($name === '' || $language === '' || $status === '') {
            return;
        }

        $this->templates->applyProviderStatus(
            $name,
            $language,
            $status,
            isset($event->details['reason']) ? (string) $event->details['reason'] : null,
            isset($event->details['provider_template_id']) ? (string) $event->details['provider_template_id'] : null,
        );
    }

    private function notify(Conversation $conversation, string $messageId): void
    {
        if ($conversation->owner_id === null) {
            return;
        }

        $this->notifier->notify([$conversation->owner_id], new NotificationMessage(
            category: 'whatsapp_marketing.inbox',
            titleKey: 'New WhatsApp message from :name',
            params: ['name' => $conversation->profile_name ?? $conversation->phone],
            linkRoute: 'whatsapp_marketing.inbox.show',
            linkParams: ['conversation' => $conversation->id],
            dedupeKey: 'wa-in:'.$messageId,
            sourceModule: Messenger::MODULE,
        ));
    }
}
