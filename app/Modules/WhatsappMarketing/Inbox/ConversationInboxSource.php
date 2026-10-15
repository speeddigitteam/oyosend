<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Inbox;

use App\Core\Access\AccessChecker;
use App\Core\Communication\Contracts\InboxReplies;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Contracts\MarksInboxRead;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxSourceDefinition;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Communication\Inbox\InboxThread;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Support\Permissions;
use App\Modules\WhatsappMarketing\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WhatsApp conversations in the unified inbox, narrowed as in this app's own inbox. A conversation can be read and
 * answered there too (ADR 0030): the reply goes through {@see Messenger::reply()}, which keeps the 24-hour window,
 * consent, suppression and quiet-hour rules; nothing here sends on its own. Reading it there clears its unread count
 * through {@see ConversationManager::markRead()}, as this app's own conversation page does.
 */
final class ConversationInboxSource implements InboxReplies, InboxSource, MarksInboxRead
{
    /** The source key; the app's realtime signals name it too (ADR 0035). */
    public const string KEY = 'whatsapp_marketing.conversations';

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly AccessChecker $checker,
        private readonly Messenger $messenger,
        private readonly WindowTracker $windows,
        private readonly ConversationManager $conversations,
    ) {}

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: self::KEY, module: 'whatsapp_marketing', channel: 'whatsapp', label: 'WhatsApp', permission: Permissions::CONVERSATIONS_VIEW);
    }

    public function items(InboxQuery $query, ?InboxCursor $after = null): array
    {
        $conversations = $this->filtered($query)
            ->where(function (Builder $states) use ($query): void {
                foreach ($query->states as $state) {
                    $states->orWhere(fn (Builder $one) => $this->inState($one, $state));
                }
            })
            ->when($after !== null, fn (Builder $older) => $older->where(fn (Builder $cursor) => $cursor
                ->where('last_message_at', '<', $after?->at)
                ->orWhere(fn (Builder $same) => $same->where('last_message_at', $after?->at)->where('id', '<', $after?->id))))
            ->with(['contact:id,first_name,last_name', 'owner.user:id,name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1)
            ->get();

        return $conversations->map(fn (Conversation $conversation): InboxItem => new InboxItem(
            id: $conversation->id,
            title: $conversation->contact?->fullName() ?? $conversation->profile_name ?? $conversation->phone,
            preview: $conversation->last_message_preview === null ? null : mb_substr($conversation->last_message_preview, 0, 140),
            at: $conversation->last_message_at?->toImmutable()->utc() ?? $conversation->created_at->toImmutable()->utc(),
            state: $this->stateOf($conversation),
            url: route('whatsapp_marketing.inbox.show', $conversation),
            contactId: $conversation->contact_id,
            ownerName: $conversation->owner?->user?->name,
            unread: $conversation->unread_count,
        ))->values()->all();
    }

    public function counts(InboxQuery $query): array
    {
        $counts = [];

        foreach (InboxState::cases() as $state) {
            $counts[$state->value] = $this->inState($this->filtered($query), $state)->count();
        }

        return $counts;
    }

    public function thread(string $id, User $viewer, int $limit = self::DEFAULT_LIMIT): ?InboxThread
    {
        $conversation = $this->visible($id, $viewer);

        if ($conversation === null) {
            return null;
        }

        $rows = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('author.user:id,name')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $replyable = $this->access->allows($viewer, Permissions::CONVERSATIONS_REPLY, $conversation);
        $blocked = $replyable ? $this->replyBlockedReason($conversation) : null;

        return new InboxThread(
            sourceKey: $this->definition()->key,
            id: $conversation->id,
            title: $conversation->contact?->fullName() ?? $conversation->profile_name ?? $conversation->phone,
            channel: $this->definition()->channel,
            contactId: $conversation->contact_id,
            url: route('whatsapp_marketing.inbox.show', $conversation),
            canReply: $replyable && $blocked === null,
            replyBlockedReason: $blocked,
            messages: $rows->take($limit)->reverse()->map(fn (Message $message): InboxMessage => $this->message($message))->values()->all(),
            hasOlder: $rows->count() > $limit,
            subtitle: $conversation->phone,
        );
    }

    public function reply(string $id, string $text, User $viewer): InboxMessage
    {
        $conversation = $this->visible($id, $viewer) ?? throw new NotFoundHttpException;

        // The same record scope as this app's own reply route: outside it the conversation is not disclosed.
        if (! $this->access->allows($viewer, Permissions::CONVERSATIONS_REPLY, $conversation)) {
            throw new NotFoundHttpException;
        }

        $membership = $this->checker->membership($viewer) ?? throw new AccessDeniedHttpException;

        try {
            $message = $this->messenger->reply($conversation, $text, $membership);
        } catch (ValidationException $refused) {
            // The app reports on its own field (`body`); the inbox composer's field is `text`.
            throw ValidationException::withMessages(['text' => array_values(array_map('strval', Arr::flatten($refused->errors())))]);
        }

        return $this->message($message->load('author.user:id,name'));
    }

    public function replyPermission(): string
    {
        return Permissions::CONVERSATIONS_REPLY;
    }

    /** As this app's own `whatsapp_marketing.inbox.read`: the conversation in the viewer's view scope, then markRead. */
    public function markRead(string $id, User $viewer): void
    {
        $conversation = $this->visible($id, $viewer);

        if ($conversation !== null) {
            $this->conversations->markRead($conversation);
        }
    }

    /** The conversation, when the viewer may open it in this app's own inbox. */
    private function visible(string $id, User $viewer): ?Conversation
    {
        return $this->access->constrain(Conversation::query(), $viewer, Permissions::CONVERSATIONS_VIEW)
            ->with('contact:id,first_name,last_name,phone')
            ->find($id);
    }

    /**
     * Why a free-form reply would be refused now. Shown only; {@see Messenger::reply()} enforces the same rules again.
     */
    private function replyBlockedReason(Conversation $conversation): ?string
    {
        if ($this->windows->serviceWindowUntil($conversation) === null) {
            return __('The 24-hour window has closed. Only an approved template can be sent now: send one from the WhatsApp inbox.');
        }

        if ($conversation->contact === null) {
            return __('Add this number to your contacts before sending.');
        }

        if (Phone::e164($conversation->contact->phone) !== $conversation->phone) {
            return __('The contact\'s phone number does not match this conversation.');
        }

        return null;
    }

    private function message(Message $message): InboxMessage
    {
        $inbound = $message->direction === Direction::Inbound;
        $text = $message->body;

        if (($text === null || $text === '') && $message->template_name !== null) {
            $text = __('Template: :name', ['name' => $message->template_name]);
        }

        return new InboxMessage(
            id: $message->id,
            direction: $inbound ? InboxMessage::IN : InboxMessage::OUT,
            text: $text === '' ? null : $text,
            at: CarbonImmutable::instance($message->occurred_at)->utc(),
            authorName: $inbound ? null : match ($message->sender_type) {
                SenderType::Agent => $message->author?->user->name ?? __('Agent'),
                SenderType::Bot => __('Chatbot'),
                SenderType::Campaign => __('Campaign'),
                SenderType::Automation => __('Automation'),
                SenderType::Customer => null,
            },
            status: $inbound ? null : $message->status->value,
            attachmentLabel: $this->attachmentLabel($message),
            attachmentKind: match ($message->type) {
                MessageType::Image, MessageType::Sticker => 'image',
                MessageType::Video => 'video',
                MessageType::Audio => 'audio',
                MessageType::Document => 'file',
                default => null,
            },
        );
    }

    /** What is attached, from the stored metadata only; the file itself is never fetched here. */
    private function attachmentLabel(Message $message): ?string
    {
        $voice = (bool) ($message->attachments[0]['voice'] ?? false);

        return match ($message->type) {
            MessageType::Image => __('Image'),
            MessageType::Document => __('Document'),
            MessageType::Audio => $voice ? __('Voice message') : __('Audio'),
            MessageType::Video => __('Video'),
            MessageType::Sticker => __('Sticker'),
            MessageType::Unsupported => __('A message this app cannot show'),
            default => null,
        };
    }

    /** @return Builder<Conversation> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->access->constrain(Conversation::query(), $query->user, Permissions::CONVERSATIONS_VIEW)
            ->whereNotNull('last_message_at')
            // "Unread" is the same counter the item shows.
            ->when($query->unread, fn (Builder $unread) => $unread->where('unread_count', '>', 0))
            ->when($query->owner === 'me', fn (Builder $mine) => $mine->where('owner_id', $query->membershipId))
            ->when($query->owner === 'unassigned', fn (Builder $nobody) => $nobody->whereNull('owner_id'))
            ->when($query->search !== '', fn (Builder $found) => $found->where(fn (Builder $match) => $match
                ->where('phone', 'like', Like::contains($query->search))
                ->orWhere('profile_name', 'like', Like::contains($query->search))
                ->orWhere('last_message_preview', 'like', Like::contains($query->search))
                ->orWhereHas('contact', fn (Builder $contacts) => $contacts->where(fn (Builder $names) => $names
                    ->where('first_name', 'like', Like::contains($query->search))
                    ->orWhere('last_name', 'like', Like::contains($query->search))))));
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    private function inState(Builder $query, InboxState $state): Builder
    {
        return match ($state) {
            InboxState::Unassigned => $query->where('status', ConversationStatus::Open)->whereNull('owner_id'),
            InboxState::Active => $query->where('status', ConversationStatus::Open)->whereNotNull('owner_id'),
            InboxState::Closed => $query->where('status', ConversationStatus::Closed),
        };
    }

    private function stateOf(Conversation $conversation): InboxState
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return InboxState::Closed;
        }

        return $conversation->owner_id === null ? InboxState::Unassigned : InboxState::Active;
    }
}
