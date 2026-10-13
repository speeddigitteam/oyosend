<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Data\InteractionEntry;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Illuminate\Database\Eloquent\Collection;

/**
 * Puts inbound messages on the shared contact timeline when the platform could not: it writes an entry only when
 * it resolves the sender to a contact, and an unknown number gets its contact later, from this app (ADR 0020,
 * decision 7). The platform's own entries and these never cover the same message, and the per-message key makes a
 * repeat a no-op.
 */
final class ConversationTimeline
{
    public const int CHUNK = 200;

    public function __construct(private readonly InteractionRecorder $timeline) {}

    public function recordInbound(Message $message, string $contactId): void
    {
        if ($message->direction !== Direction::Inbound || $message->body === null) {
            return;
        }

        $this->timeline->record(new InteractionEntry(
            type: 'whatsapp',
            summary: mb_substr(trim($message->body), 0, 200) ?: __('Message received'),
            contactId: $contactId,
            body: $message->body,
            direction: 'inbound',
            occurredAt: $message->occurred_at,
            sourceModule: Messenger::MODULE,
            sourceType: 'message',
            sourceId: $message->id,
            dedupeKey: 'whatsapp:inbound:'.$message->id,
        ));
    }

    /** The inbound messages a conversation received before it had a contact. Stops if it was re-linked since. */
    public function backfill(string $conversationId, string $contactId): void
    {
        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null || $conversation->contact_id !== $contactId) {
            return;
        }

        Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', Direction::Inbound)
            ->whereNotNull('body')
            ->chunkById(self::CHUNK, function (Collection $messages) use ($contactId): void {
                foreach ($messages as $message) {
                    $this->recordInbound($message, $contactId);
                }
            });
    }
}
