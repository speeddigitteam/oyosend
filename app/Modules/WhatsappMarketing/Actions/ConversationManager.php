<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Events\ConversationAssigned;
use App\Modules\WhatsappMarketing\Events\MessageReceived;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Support\Phone;
use App\Modules\WhatsappMarketing\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conversations and the inbound side of them. A conversation is one thread per phone number. Everything the
 * customer sent is untrusted: text is cleaned and capped, attachments are kept as metadata only.
 */
final class ConversationManager
{
    public const int MAX_TAGS = 10;

    public function __construct(
        private readonly WindowTracker $windows,
        private readonly SettingsManager $settings,
        private readonly AgentManager $agents,
        private readonly ContactDirectory $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record one inbound message. Returns null when it is a duplicate (the provider's message id was seen) or
     * has no usable sender number.
     */
    public function receive(ChannelEvent $event): ?InboundResult
    {
        $phone = Phone::e164($event->address);

        if ($phone === null) {
            return null;
        }

        $kind = (string) ($event->details['message_type'] ?? 'text');
        $type = match ($kind) {
            'text', 'button' => MessageType::Text,
            'interactive' => MessageType::Interactive,
            'image' => MessageType::Image,
            'document' => MessageType::Document,
            'audio' => MessageType::Audio,
            'video' => MessageType::Video,
            'sticker' => MessageType::Sticker,
            default => MessageType::Unsupported,
        };
        $body = $event->text === null ? null : (Text::clean($event->text, Messenger::MAX_TEXT) ?: null);
        $attachments = in_array($type, [MessageType::Image, MessageType::Document, MessageType::Audio, MessageType::Video, MessageType::Sticker], true)
            ? [[
                'kind' => $type->value,
                'mime' => Text::clean((string) ($event->details['media_mime'] ?? ''), 100, false),
                'has_caption' => $body !== null,
            ]]
            : null;
        $at = $this->occurredAt($event);
        $name = Text::clean((string) ($event->details['profile_name'] ?? ''), 120, false) ?: null;

        try {
            $result = DB::transaction(function () use ($event, $phone, $type, $body, $attachments, $at, $name): ?InboundResult {
                if (Message::query()->where('provider_message_id', $event->eventId)->exists()) {
                    return null;
                }

                $existing = Conversation::query()->where('phone', $phone)->lockForUpdate()->first();
                $new = $existing === null;
                $conversation = $existing ?? new Conversation(['phone' => $phone, 'status' => ConversationStatus::Open, 'unread_count' => 0]);

                $contactId = $event->contactId ?? $conversation->contact_id ?? $this->createdContact($phone, $name);

                $conversation->fill([
                    'contact_id' => $contactId,
                    'profile_name' => $name ?? $conversation->profile_name,
                    'status' => ConversationStatus::Open,
                    'closed_at' => null,
                    'unread_count' => $conversation->unread_count + 1,
                    'last_message_at' => $at,
                    'last_message_preview' => Text::preview($body ?? ($type === MessageType::Text ? __('Message') : __('Attachment'))),
                    'last_message_direction' => 'inbound',
                ]);

                // The bot starts again for a customer who writes after the window closed.
                $reopened = $new || $conversation->last_inbound_at === null || $conversation->last_inbound_at->lt($at->subHours(WindowTracker::HOURS));

                if ($reopened) {
                    $conversation->bot_paused = false;
                    $conversation->bot_state = null;
                    $conversation->bot_state_expires_at = null;
                }

                $conversation->last_inbound_at = $conversation->last_inbound_at === null || $conversation->last_inbound_at->lt($at) ? $at : $conversation->last_inbound_at;
                $conversation->save();

                try {
                    $message = Message::query()->create([
                        'conversation_id' => $conversation->id,
                        'direction' => Direction::Inbound,
                        'sender_type' => SenderType::Customer,
                        'type' => $type,
                        'body' => $body,
                        'status' => MessageStatus::Received,
                        'provider_message_id' => mb_substr($event->eventId, 0, 190),
                        'attachments' => $attachments,
                        'occurred_at' => $at,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return null;
                }

                $before = $this->windows->current($conversation, $at);
                $this->windows->openForInbound($conversation, $at);

                return new InboundResult($message, $conversation, $new, $before === null);
            });
        } catch (UniqueConstraintViolationException) {
            // Two first messages from one number raced for the conversation row: the loser is a duplicate.
            return null;
        }

        if ($result !== null) {
            event(new MessageReceived([
                'conversation_id' => $result->conversation->id,
                'message_id' => $result->message->id,
                'contact_id' => $result->conversation->contact_id,
                'owner_id' => $result->conversation->owner_id,
                'message_type' => $result->message->type->value,
                'new_conversation' => $result->newConversation,
            ]));
        }

        return $result;
    }

    /** The conversation for a contact's number, or null when the contact has no international number. */
    public function forContact(Contact $contact): ?Conversation
    {
        $phone = Phone::e164($contact->phone);

        if ($phone === null) {
            return null;
        }

        $conversation = Conversation::query()->where('phone', $phone)->first();

        if ($conversation !== null) {
            if ($conversation->contact_id === null) {
                $conversation->forceFill(['contact_id' => $contact->id])->save();
            }

            return $conversation;
        }

        try {
            return Conversation::query()->create([
                'contact_id' => $contact->id,
                'phone' => $phone,
                'status' => ConversationStatus::Open,
                'unread_count' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()->where('phone', $phone)->firstOrFail();
        }
    }

    /**
     * Hand a conversation to an active agent, or to nobody. The bot steps back for a conversation a person owns.
     *
     * @throws ValidationException
     */
    public function assign(Conversation $conversation, ?TenantMembership $assignee, string $source = 'manual'): Conversation
    {
        if ($assignee !== null && $this->agents->activeMembership($assignee->id) === null) {
            throw ValidationException::withMessages(['assignee_id' => __('Choose an enrolled, active agent.')]);
        }

        DB::transaction(function () use ($conversation, $assignee, $source): void {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->owner_id;

            if ($previous === $assignee?->id) {
                $conversation->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $locked->assignTo($assignee);

            // Automatic assignment leaves the bot running; a person or a flow taking over pauses it.
            if ($assignee !== null && $source !== 'auto') {
                $locked->bot_paused = true;
                $locked->bot_state = null;
                $locked->bot_state_expires_at = null;
            }

            $locked->save();
            $this->audit->record('whatsapp_marketing.conversation.assigned', $locked, [
                'previous_owner_id' => $previous,
                'owner_id' => $assignee?->id,
                'source' => $source,
            ]);
            $conversation->setRawAttributes($locked->getAttributes(), true);

            event(new ConversationAssigned([
                'conversation_id' => $locked->id,
                'contact_id' => $locked->contact_id,
                'owner_id' => $assignee?->id,
                'previous_owner_id' => $previous,
                'source' => $source,
            ]));
        });

        return $conversation;
    }

    public function setStatus(Conversation $conversation, ConversationStatus $status): Conversation
    {
        if ($conversation->status === $status) {
            return $conversation;
        }

        DB::transaction(function () use ($conversation, $status): void {
            $conversation->fill([
                'status' => $status,
                'closed_at' => $status === ConversationStatus::Closed ? CarbonImmutable::now() : null,
            ]);

            if ($status === ConversationStatus::Closed) {
                $conversation->bot_state = null;
                $conversation->bot_state_expires_at = null;
            }

            $conversation->save();
            $this->audit->record('whatsapp_marketing.conversation.'.($status === ConversationStatus::Closed ? 'closed' : 'reopened'), $conversation);
        });

        return $conversation;
    }

    /**
     * @param  list<string>  $tags
     *
     * @throws ValidationException
     */
    public function setTags(Conversation $conversation, array $tags): Conversation
    {
        $clean = [];

        foreach ($tags as $tag) {
            $value = strtolower(trim((string) $tag));

            if ($value === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,29}$/', $value) !== 1) {
                throw ValidationException::withMessages(['tags' => __('A tag uses lower-case letters, digits, dashes and underscores, up to 30 characters.')]);
            }

            $clean[$value] = $value;
        }

        if (count($clean) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => __('A conversation can have up to :max tags.', ['max' => self::MAX_TAGS])]);
        }

        $conversation->tags = $clean === [] ? null : array_values($clean);
        $conversation->save();

        return $conversation;
    }

    public function addTag(Conversation $conversation, string $tag): Conversation
    {
        return $this->setTags($conversation, [...($conversation->tags ?? []), $tag]);
    }

    /** @throws ValidationException */
    public function linkContact(Conversation $conversation, Contact $contact): Conversation
    {
        if (Phone::e164($contact->phone) !== $conversation->phone) {
            throw ValidationException::withMessages(['contact_id' => __('The contact\'s phone number does not match this conversation.')]);
        }

        $conversation->forceFill(['contact_id' => $contact->id])->save();

        return $conversation;
    }

    /** Create a shared contact from the number (and WhatsApp profile name) of a conversation, and link it. */
    public function createContact(Conversation $conversation, ?string $name = null, ?TenantMembership $owner = null): Contact
    {
        $first = Text::clean($name ?? $conversation->profile_name ?? '', 120, false) ?: $conversation->phone;

        $contact = DB::transaction(function () use ($conversation, $first, $owner): Contact {
            $contact = $this->contacts->createContact(['first_name' => $first, 'phone' => $conversation->phone], $owner);
            $conversation->forceFill(['contact_id' => $contact->id])->save();

            return $contact;
        });

        return $contact;
    }

    public function markRead(Conversation $conversation): void
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    /** With the workspace option on, a number nobody has is added to the directory as it first writes. */
    private function createdContact(string $phone, ?string $name): ?string
    {
        if (! $this->settings->current()->auto_create_contacts) {
            return null;
        }

        return $this->contacts->createContact(['first_name' => $name ?? $phone, 'phone' => $phone], null)->id;
    }

    private function occurredAt(ChannelEvent $event): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $stamp = (string) ($event->details['timestamp'] ?? '');

        if ($stamp !== '' && ctype_digit($stamp)) {
            $at = CarbonImmutable::createFromTimestampUTC((int) $stamp);

            // A provider clock is trusted only within sensible bounds.
            if ($at->lte($now->addMinutes(5)) && $at->gte($now->subDays(2))) {
                return $at;
            }
        }

        return $now;
    }
}
