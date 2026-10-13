<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ConversationNote;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal notes on a conversation. A note is for the team only: it is never sent, never put on the customer's
 * timeline, and the audit trail records that a note was added or removed, not what it said.
 */
final class NoteManager
{
    public const int MAX_LENGTH = 2000;

    /** At most this many notes on one conversation, so a thread stays readable and bounded. */
    public const int MAX_NOTES = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function add(Conversation $conversation, string $body, TenantMembership $author): ConversationNote
    {
        $text = Text::clean($body, self::MAX_LENGTH);

        if ($text === '') {
            throw ValidationException::withMessages(['body' => __('Write the note first.')]);
        }

        return DB::transaction(function () use ($conversation, $text, $author): ConversationNote {
            // Serialised per conversation, so two agents cannot both pass the cap.
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            if (ConversationNote::query()->where('conversation_id', $conversation->id)->count() >= self::MAX_NOTES) {
                throw ValidationException::withMessages(['body' => __('This conversation has the most notes it can hold. Remove an old one first.')]);
            }

            $note = ConversationNote::query()->create([
                'conversation_id' => $conversation->id,
                'author_id' => $author->id,
                'body' => $text,
            ]);
            $this->audit->record('whatsapp_marketing.note.added', $conversation, ['note_id' => $note->id]);

            return $note;
        });
    }

    public function remove(ConversationNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $conversation = Conversation::query()->find($note->conversation_id);
            $note->delete();

            if ($conversation !== null) {
                $this->audit->record('whatsapp_marketing.note.removed', $conversation, ['note_id' => $note->id]);
            }
        });
    }
}
