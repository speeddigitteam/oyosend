<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Actions;

use App\Core\Communication\Contracts\InboxSignals;
use App\Modules\Social\Comments\Support\UntrustedText;
use App\Modules\Social\Messages\Data\MessagingEvent;
use App\Modules\Social\Messages\Inbox\DirectMessageInboxSource;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\DirectMessage;
use App\Modules\Social\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies one verified messaging event to the workspace's conversations. Idempotent: a message is stored once per
 * platform message id (`mid`, unique per workspace), so a redelivered webhook changes nothing. The caller has already
 * checked the Page claim, the workspace, the entitlement and that capture is on.
 *
 * Text is untrusted: control and bidirectional-override characters are stripped and the length is capped. An
 * attachment is stored as its type only; its file is never fetched. No contact is created (ADR 0031).
 */
final class MessageIngestor
{
    public function __construct(private readonly InboxSignals $signals) {}

    public function apply(SocialAccount $account, MessagingEvent $event): void
    {
        match ($event->kind) {
            MessagingEvent::MESSAGE, MessagingEvent::ECHO => $this->store($account, $event),
            MessagingEvent::READ => $this->markRead($account, $event),
            MessagingEvent::DELIVERY => $this->markDelivered($account, $event),
            MessagingEvent::DELETED => $this->markDeleted($event),
            default => null,
        };
    }

    private function store(SocialAccount $account, MessagingEvent $event): void
    {
        if ($event->mid === null || DirectMessage::query()->where('provider_message_id', $event->mid)->exists()) {
            return;
        }

        $inbound = $event->kind === MessagingEvent::MESSAGE;
        $text = $event->text === null ? '' : UntrustedText::clean($event->text, DirectMessage::TEXT_MAX);
        $text = trim($text) === '' ? null : $text;

        DB::transaction(function () use ($account, $event, $inbound, $text): void {
            $conversation = $this->conversation($account, $event);

            if (! $inbound && $this->claimPendingReply($conversation, $event, $text)) {
                return;
            }

            try {
                DirectMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'direction' => $inbound ? DirectMessage::IN : DirectMessage::OUT,
                    'provider_message_id' => $event->mid,
                    'text' => $text,
                    'attachment_type' => $event->attachmentType,
                    'status' => $inbound ? DirectMessage::RECEIVED : DirectMessage::SENT,
                    'sent_at' => $event->at,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same message arrived twice at once: the other delivery stored it.
                return;
            }

            if ($conversation->last_message_at === null || $event->at->greaterThanOrEqualTo($conversation->last_message_at)) {
                $conversation->forceFill(['last_message_at' => $event->at, 'last_message_preview' => $this->preview($text)]);
            }

            if ($inbound) {
                if ($conversation->last_inbound_at === null || $event->at->greaterThan($conversation->last_inbound_at)) {
                    $conversation->forceFill(['last_inbound_at' => $event->at]);
                }

                // A message from the person reopens the conversation and waits to be read.
                $conversation->status = DirectConversation::OPEN;
                $conversation->unread_count++;
            } else {
                // The Page answered (here or elsewhere): what came before has been read.
                $conversation->unread_count = 0;
            }

            $conversation->save();
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);
        });
    }

    /**
     * The conversation for the event's person on this Page, created when it is the first message. Locked, so two
     * messages arriving together count both.
     */
    private function conversation(SocialAccount $account, MessagingEvent $event): DirectConversation
    {
        $find = fn (): ?DirectConversation => DirectConversation::query()
            ->where('social_account_id', $account->id)->where('platform', $event->platform)->where('participant_id', $event->participantId)
            ->lockForUpdate()->first();

        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $conversation = new DirectConversation([
                'social_account_id' => $account->id,
                'platform' => $event->platform,
                'participant_id' => $event->participantId,
                'unread_count' => 0,
                'status' => DirectConversation::OPEN,
            ]);
            // Arrives from the platform, not from a member: nobody owns it until it is assigned.
            $conversation->keepUnowned()->save();

            return $conversation;
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \RuntimeException('The conversation could not be stored.');
        }
    }

    /**
     * The echo of a reply sent from this application can arrive before the send call returned: it then completes the
     * reply that is still being sent instead of adding a second copy.
     */
    private function claimPendingReply(DirectConversation $conversation, MessagingEvent $event, ?string $text): bool
    {
        $pending = DirectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', DirectMessage::OUT)
            ->where('status', DirectMessage::SENDING)
            ->whereNull('provider_message_id')
            ->where('text', $text)
            ->orderBy('created_at')
            ->first();

        if ($pending === null) {
            return false;
        }

        $pending->forceFill(['provider_message_id' => $event->mid])->save();

        return true;
    }

    private function markRead(SocialAccount $account, MessagingEvent $event): void
    {
        $conversation = $this->existing($account, $event);

        if ($conversation === null) {
            return;
        }

        $until = $event->watermark;

        if ($until === null && $event->mids !== []) {
            $read = DirectMessage::query()->where('conversation_id', $conversation->id)->whereIn('provider_message_id', $event->mids)->max('sent_at');
            $until = is_string($read) ? CarbonImmutable::parse($read) : null;
        }

        if ($until === null) {
            return;
        }

        $changed = DirectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', DirectMessage::OUT)
            ->whereIn('status', [DirectMessage::SENT, DirectMessage::DELIVERED])
            ->where('sent_at', '<=', $until)
            ->update(['status' => DirectMessage::READ]);

        if ($changed > 0) {
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);
        }
    }

    private function markDelivered(SocialAccount $account, MessagingEvent $event): void
    {
        $conversation = $this->existing($account, $event);

        if ($conversation === null) {
            return;
        }

        $changed = DirectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', DirectMessage::OUT)
            ->where('status', DirectMessage::SENT)
            ->where(function ($query) use ($event): void {
                $query->whereIn('provider_message_id', $event->mids === [] ? [''] : $event->mids);

                if ($event->watermark !== null) {
                    $query->orWhere('sent_at', '<=', $event->watermark);
                }
            })
            ->update(['status' => DirectMessage::DELIVERED]);

        if ($changed > 0) {
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);
        }
    }

    /** The person unsent a message: its text is no longer shown or kept. */
    private function markDeleted(MessagingEvent $event): void
    {
        if ($event->mid === null) {
            return;
        }

        DirectMessage::query()
            ->where('provider_message_id', $event->mid)
            ->where('direction', DirectMessage::IN)
            ->update(['text' => null, 'attachment_type' => null, 'status' => DirectMessage::DELETED]);

        $conversationId = DirectMessage::query()->where('provider_message_id', $event->mid)->value('conversation_id');

        if (is_string($conversationId)) {
            $this->signals->changed(DirectMessageInboxSource::keyFor($event->platform), $conversationId);
        }
    }

    private function existing(SocialAccount $account, MessagingEvent $event): ?DirectConversation
    {
        return DirectConversation::query()
            ->where('social_account_id', $account->id)->where('platform', $event->platform)->where('participant_id', $event->participantId)
            ->first();
    }

    /** The list preview: the text on one line, or null for an attachment only (shown as "Attachment"). */
    private function preview(?string $text): ?string
    {
        return $text === null ? null : mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 280);
    }
}
