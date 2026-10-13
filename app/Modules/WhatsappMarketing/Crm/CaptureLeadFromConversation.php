<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Crm;

use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\LeadCapture;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Events\MessageReceived;
use App\Modules\WhatsappMarketing\Models\CapturedLead;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Throwable;

/**
 * Turns an inbound WhatsApp conversation into a CRM lead through the core `LeadIntake` contract, without importing
 * a CRM model. It listens to the existing `MessageReceived` domain event, so the inbound handler and the chatbot
 * are never touched (ADR 0023, docs/WHATSAPP.md section 16).
 *
 * It acts only when the workspace switched lead capture on (off by default). A lead is captured when the message
 * starts a new conversation (an unknown number writing for the first time) or when its text contains a keyword the
 * workspace configured. Capture is idempotent on the message id (`wa-lead:{message id}`) and the contract dedupes
 * the contact, so a reprocessed event never creates a second contact or lead. When the workspace has no CRM the
 * contract answers `unavailable` and nothing happens.
 *
 * The outcome is recorded (ids only) and the conversation is tagged `crm-lead` so the team sees it on the thread.
 */
final class CaptureLeadFromConversation
{
    public const string TAG = 'crm-lead';

    public const string SOURCE = 'whatsapp';

    public function __construct(
        private readonly LeadIntake $intake,
        private readonly LeadCaptureSettings $settings,
        private readonly ConversationManager $conversations,
    ) {}

    public function handle(MessageReceived $event): void
    {
        try {
            $this->capture($event->payload());
        } catch (Throwable $exception) {
            // Lead capture must never break receiving a customer's message.
            report($exception);
        }
    }

    /** @param array<string, scalar|null> $payload */
    private function capture(array $payload): void
    {
        if (! $this->settings->current()->enabled) {
            return;
        }

        $conversationId = (string) ($payload['conversation_id'] ?? '');
        $messageId = (string) ($payload['message_id'] ?? '');

        if ($conversationId === '' || $messageId === '') {
            return;
        }

        $conversation = Conversation::query()->with('contact:id,first_name,last_name')->find($conversationId);
        $message = Message::query()->find($messageId);

        if ($conversation === null || $message === null || $message->direction !== Direction::Inbound) {
            return;
        }

        $isNew = ($payload['new_conversation'] ?? false) === true;

        if (! $isNew && ! $this->matchesKeyword((string) $message->body)) {
            return;
        }

        // Already captured for this message (a reprocessed event): nothing to do.
        if (CapturedLead::query()->where('message_id', $messageId)->exists()) {
            return;
        }

        $result = $this->intake->capture(new LeadCapture(
            source: self::SOURCE,
            idempotencyKey: 'wa-lead:'.$messageId,
            name: $conversation->contact?->fullName() ?: $conversation->profile_name,
            phone: $conversation->phone,
            answers: $message->body === null || trim((string) $message->body) === '' ? [] : ['message' => (string) $message->body],
        ));

        if (! $result->wasCaptured()) {
            // CRM is not entitled, or nothing was written: leave the conversation untouched.
            return;
        }

        CapturedLead::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'contact_id' => $result->contactId,
            'lead_id' => $result->leadId,
            'source' => self::SOURCE,
            'status' => $result->status->value,
        ]);

        if (! in_array(self::TAG, $conversation->tags ?? [], true)) {
            $this->conversations->addTag($conversation, self::TAG);
        }
    }

    private function matchesKeyword(string $body): bool
    {
        $keywords = $this->settings->current()->keywords ?? [];

        if ($keywords === [] || trim($body) === '') {
            return false;
        }

        $haystack = mb_strtolower($body);

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
