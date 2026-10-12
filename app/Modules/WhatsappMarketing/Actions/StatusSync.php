<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Template;
use Carbon\CarbonImmutable;

/**
 * Applies the communication platform's `MarketingMessageStatusChanged` events for messages this app queued
 * (and only those: other apps' events are ignored). A provider-accepted template opens the conversation window
 * the plan limit counts.
 */
final class StatusSync
{
    public function __construct(
        private readonly WhatsappAccess $access,
        private readonly MessageLocator $locator,
        private readonly StatusProgress $progress,
        private readonly WindowTracker $windows,
    ) {}

    public function handle(MarketingMessageStatusChanged $event): void
    {
        if ($event->module() !== Messenger::MODULE || ! $this->access->entitled()) {
            return;
        }

        $payload = $event->payload();
        $state = $event->state();
        $at = CarbonImmutable::now();
        $campaignId = is_string($payload['campaign_id'] ?? null) ? $payload['campaign_id'] : null;
        $recipientId = is_string($payload['recipient_id'] ?? null) ? $payload['recipient_id'] : null;
        $code = is_string($payload['failure_code'] ?? null) ? $payload['failure_code'] : null;

        ['recipient' => $recipient, 'message' => $message] = $this->locator->find($campaignId, $recipientId, $event->messageId());

        [$messageStatus, $recipientState] = match ($state) {
            DeliveryState::Sent => [MessageStatus::Sent, RecipientState::Sent],
            DeliveryState::Delivered => [MessageStatus::Delivered, RecipientState::Delivered],
            DeliveryState::Blocked => [MessageStatus::Blocked, RecipientState::Blocked],
            DeliveryState::Failed, DeliveryState::Bounced, DeliveryState::Cancelled => [MessageStatus::Failed, RecipientState::Failed],
            default => [null, null],
        };

        if ($messageStatus === null || $recipientState === null) {
            return;
        }

        if ($message !== null) {
            if ($message->communication_message_id === null) {
                $message->forceFill(['communication_message_id' => $event->messageId()])->save();
            }
            $this->progress->message($message, $messageStatus, $code ?? $state->value, $at);
        }

        if ($recipient !== null) {
            if ($recipient->communication_message_id === null) {
                $recipient->forceFill(['communication_message_id' => $event->messageId()])->save();
            }
            $this->progress->recipient($recipient, $recipientState, $code ?? $state->value, $at);
        }

        if ($message !== null && $message->template_id !== null && in_array($state, [DeliveryState::Sent, DeliveryState::Delivered], true)) {
            $conversation = Conversation::query()->find($message->conversation_id);
            $template = Template::query()->find($message->template_id);

            if ($conversation !== null) {
                $this->windows->openForBusiness($conversation, $template?->category->value ?? 'marketing', $at);
            }
        }
    }
}
