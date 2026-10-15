<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Enums\ChatDirection;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\ChatAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The two deliberate member actions on a website chat (ADR 0032, amendment of 2026-10-14). Nothing here runs on its
 * own: a member clicks, the caller has checked the permission and the chat's reach ({@see ChatAccess}).
 *
 *  - **Save the visitor as a contact.** An existing shared contact with the visitor's email address (any in the
 *    workspace, case-insensitive, oldest first) is linked instead of creating a duplicate; otherwise a contact is
 *    created through {@see ContactDirectory} from what the visitor typed, owned by the member. A chat that is already
 *    linked stays linked.
 *  - **Turn the chat into a ticket.** One ticket per chat: the conversation row is locked, a linked ticket is returned
 *    as it is, and the ticket's `source_key` (`live_chat:{id}`) makes a repeat return the same ticket. The ticket is
 *    created through {@see TicketManager::create()} with channel `chat` and **no customer message**; the whole
 *    transcript (both sides, with times and member names) is written as internal notes, which never leave the
 *    workspace. So no reply event fires and nothing is emailed to the visitor.
 *
 * Both are audited with ids only (no name, email or message text).
 */
final class LiveChatConversion
{
    /** Characters per transcript note, under the ticket message limit of 20 000. */
    public const int TRANSCRIPT_CHUNK = 18000;

    /** The ticket's `source_key` prefix; the conversation id follows. */
    public const string SOURCE_PREFIX = 'live_chat:';

    public function __construct(
        private readonly ContactDirectory $directory,
        private readonly TicketManager $tickets,
        private readonly LiveChatManager $chats,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Links the chat to a shared contact for its visitor: the existing one with the same email address, or a new one.
     *
     * @return array{contact: Contact, created: bool, linked: bool} `linked` is false when the chat already had a contact
     *
     * @throws ValidationException on `contact` when the visitor gave neither a name nor an email address
     */
    public function saveContact(ChatConversation $conversation, TenantMembership $by): array
    {
        return DB::transaction(function () use ($conversation, $by): array {
            $locked = $this->lock($conversation);
            $current = $locked->contact_id === null ? null : Contact::query()->find($locked->contact_id);

            if ($current !== null) {
                return ['contact' => $current, 'created' => false, 'linked' => false];
            }

            $email = $this->email($locked->email);
            $name = $this->name($locked->name);

            if ($email === null && $name === null) {
                throw ValidationException::withMessages(['contact' => __('This visitor gave no name and no email address, so there is nothing to save.')]);
            }

            $contact = $email === null ? null : Contact::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();
            $created = $contact === null;

            if ($contact === null) {
                [$first, $last] = $this->split($name, $email);
                $contact = $this->directory->createContact(['first_name' => $first, 'last_name' => $last, 'email' => $email], $by);
            }

            $locked->contact_id = $contact->id;
            $locked->save();

            $this->audit->record('support.live_chat.contact_linked', $locked, [
                'conversation_id' => $locked->id,
                'contact_id' => $contact->id,
                'contact_created' => $created,
                'by' => $by->id,
            ]);

            return ['contact' => $contact, 'created' => $created, 'linked' => true];
        });
    }

    /**
     * Opens a ticket from the chat, once. A chat that already has its ticket returns that ticket unchanged.
     *
     * @param  array{subject?: string|null, priority?: string|null, queue_id?: string|null}  $input
     * @return array{ticket: Ticket, created: bool}
     *
     * @throws ValidationException on `ticket` when the chat has neither an email address nor a contact, and on the
     *                             ticket fields as {@see TicketManager::create()} raises them
     */
    public function createTicket(ChatConversation $conversation, array $input, TenantMembership $by, bool $close = false): array
    {
        return DB::transaction(function () use ($conversation, $input, $by, $close): array {
            $locked = $this->lock($conversation);
            $existing = $locked->ticket_id === null ? null : Ticket::query()->find($locked->ticket_id);

            if ($existing !== null) {
                return ['ticket' => $existing, 'created' => false];
            }

            $contactId = $locked->contact_id !== null && Contact::query()->whereKey($locked->contact_id)->exists() ? $locked->contact_id : null;

            if ($contactId === null && $locked->email === null) {
                throw ValidationException::withMessages(['ticket' => __('A ticket needs the visitor\'s email address or a contact. Save the visitor as a contact first.')]);
            }

            $messages = ChatMessage::query()
                ->where('conversation_id', $locked->id)
                ->with('sentBy.user:id,name')
                ->orderBy('seq')
                ->get();
            $subject = trim((string) ($input['subject'] ?? ''));

            $ticket = $this->tickets->create(array_filter([
                'subject' => $subject !== '' ? $subject : $this->defaultSubject($locked),
                'priority' => $input['priority'] ?? null,
                'queue_id' => $input['queue_id'] ?? null,
                'contact_id' => $contactId,
                'requester_name' => $locked->name,
                'requester_email' => $locked->email,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''), $by, TicketChannel::Chat, self::SOURCE_PREFIX.$locked->id, seedMessage: false);

            foreach ($this->transcript($locked, $messages) as $part => $body) {
                $this->tickets->addMessage($ticket, MessageKind::InternalNote, $body, $by, sourceKey: self::SOURCE_PREFIX.$locked->id.':transcript:'.($part + 1));
            }

            $locked->ticket_id = $ticket->id;
            $locked->save();

            $this->audit->record('support.live_chat.converted_to_ticket', $locked, [
                'conversation_id' => $locked->id,
                'ticket_id' => $ticket->id,
                'messages' => $messages->count(),
                'by' => $by->id,
            ]);

            if ($close) {
                $this->chats->close($locked, $by);
            }

            return ['ticket' => $ticket, 'created' => true];
        });
    }

    /** The suggested ticket subject: the visitor's first message on one line, at most 200 characters. */
    public function defaultSubject(ChatConversation $conversation): string
    {
        $first = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ChatDirection::In->value)
            ->orderBy('seq')
            ->value('text');
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) $first));

        if ($line === '') {
            return __('Website chat with :name', ['name' => $conversation->displayName()]);
        }

        return mb_strlen($line) > 200 ? rtrim(mb_substr($line, 0, 199)).'…' : $line;
    }

    /**
     * The transcript as plain-text note bodies of at most {@see TRANSCRIPT_CHUNK} characters, split between messages.
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<string>
     */
    private function transcript(ChatConversation $conversation, Collection $messages): array
    {
        $timezone = $this->context->require()->timezone ?: config('app.timezone');
        $visitor = $conversation->name ?? __('Visitor');
        $header = __('Transcript of the website chat (:count messages).', ['count' => $messages->count()])
            .($conversation->page_url === null ? '' : "\n".__('Started on :page', ['page' => $conversation->page_url]));
        $parts = [];
        $current = $header;

        foreach ($messages as $message) {
            $author = $message->direction === ChatDirection::In ? $visitor : ($message->sentBy->user->name ?? __('Agent'));
            $line = '['.$message->created_at->copy()->setTimezone($timezone)->format('Y-m-d H:i').'] '.$author.': '.$message->text;

            if (mb_strlen($current) + mb_strlen($line) + 2 > self::TRANSCRIPT_CHUNK) {
                $parts[] = $current;
                $current = __('Transcript of the website chat, continued.');
            }

            $current .= "\n\n".$line;
        }

        $parts[] = $current;

        return $parts;
    }

    private function lock(ChatConversation $conversation): ChatConversation
    {
        return ChatConversation::query()->lockForUpdate()->findOrFail($conversation->id);
    }

    private function email(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    private function name(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        return $name === '' ? null : $name;
    }

    /**
     * First and last name from what the visitor typed; without a name, the email address's local part.
     *
     * @return array{0: string, 1: string|null}
     */
    private function split(?string $name, ?string $email): array
    {
        if ($name === null) {
            return [mb_substr(Str::before((string) $email, '@'), 0, 100), null];
        }

        $words = explode(' ', $name, 2);
        $last = isset($words[1]) ? mb_substr($words[1], 0, 100) : null;

        return [mb_substr($words[0], 0, 100), $last === '' ? null : $last];
    }
}
