<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Inbox;

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
use App\Modules\Social\Messages\Actions\ConversationAccess;
use App\Modules\Social\Messages\Actions\DirectMessages;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\DirectMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Facebook Messenger or Instagram direct messages in the unified inbox (ADR 0030, ADR 0031): one item is one
 * conversation between a connected Page and one person, narrowed exactly as on the Social Messages page
 * ({@see ConversationAccess}). A reply goes through {@see DirectMessages::reply()}, the same action the Social page
 * uses, which keeps the 24-hour window; nothing here calls the platform. While the operator has not switched the
 * platform on, or no Page captures it, the source lists nothing.
 */
abstract class DirectMessageInboxSource implements InboxReplies, InboxSource, MarksInboxRead
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly AccessChecker $checker,
        private readonly DirectMessages $messages,
    ) {}

    /** The source key of a platform's conversations (`social.messenger`, `social.instagram`); realtime signals name it too (ADR 0035). */
    public static function keyFor(string $platform): string
    {
        return 'social.'.$platform;
    }

    /** `messenger` or `instagram`. */
    abstract protected function platform(): string;

    abstract protected function label(): string;

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: self::keyFor($this->platform()), module: 'social', channel: $this->platform(), label: $this->label(), permission: ConversationAccess::VIEW);
    }

    public function items(InboxQuery $query, ?InboxCursor $after = null): array
    {
        if (! MessagingAvailability::platformEnabled($this->platform())) {
            return [];
        }

        $conversations = $this->filtered($query)
            ->where(function (Builder $states) use ($query): void {
                foreach ($query->states as $state) {
                    $states->orWhere(fn (Builder $one) => $this->inState($one, $state));
                }
            })
            ->when($after !== null, fn (Builder $older) => $older->where(fn (Builder $cursor) => $cursor
                ->where('last_message_at', '<', $after?->at)
                ->orWhere(fn (Builder $same) => $same->where('last_message_at', $after?->at)->where('id', '<', $after?->id))))
            ->with(['account:id,name', 'owner.user:id,name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1)
            ->get();

        return $conversations->map(fn (DirectConversation $conversation): InboxItem => new InboxItem(
            id: $conversation->id,
            title: $conversation->displayName(),
            preview: $this->preview($conversation),
            at: $this->lastActivity($conversation),
            state: $this->stateOf($conversation),
            url: $this->url($conversation),
            ownerName: $conversation->owner?->user?->name,
            unread: $conversation->unread_count,
        ))->values()->all();
    }

    public function counts(InboxQuery $query): array
    {
        $counts = [];

        foreach (InboxState::cases() as $state) {
            $counts[$state->value] = MessagingAvailability::platformEnabled($this->platform())
                ? $this->inState($this->filtered($query), $state)->count()
                : 0;
        }

        return $counts;
    }

    public function thread(string $id, User $viewer, int $limit = self::DEFAULT_LIMIT): ?InboxThread
    {
        $conversation = $this->visible($id, $viewer);

        if ($conversation === null) {
            return null;
        }

        $rows = DirectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('sender.user:id,name')
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $replyable = $this->access->allows($viewer, ConversationAccess::REPLY, $conversation);
        $blocked = $replyable ? $this->messages->blockedReason($conversation) : null;

        return new InboxThread(
            sourceKey: $this->definition()->key,
            id: $conversation->id,
            title: $conversation->displayName(),
            channel: $this->platform(),
            contactId: null,
            url: $this->url($conversation),
            canReply: $replyable && $blocked === null,
            replyBlockedReason: $blocked,
            messages: $rows->take($limit)->reverse()->map(fn (DirectMessage $message): InboxMessage => $this->message($message, $conversation))->values()->all(),
            hasOlder: $rows->count() > $limit,
            subtitle: $conversation->account->name,
        );
    }

    public function reply(string $id, string $text, User $viewer): InboxMessage
    {
        $conversation = $this->visible($id, $viewer) ?? throw new NotFoundHttpException;

        // The same scope and allow-list as the Social Messages page's own reply route.
        if (! $this->access->allows($viewer, ConversationAccess::REPLY, $conversation)) {
            throw new NotFoundHttpException;
        }

        $membership = $this->checker->membership($viewer) ?? throw new AccessDeniedHttpException;

        // A refusal is already a validation error on `text`, written for a person.
        $message = $this->messages->reply($conversation, $text, $membership);

        return $this->message($message->load('sender.user:id,name'), $conversation);
    }

    public function replyPermission(): string
    {
        return ConversationAccess::REPLY;
    }

    public function markRead(string $id, User $viewer): void
    {
        $conversation = $this->visible($id, $viewer);

        if ($conversation !== null) {
            $this->messages->markRead($conversation);
        }
    }

    /** The conversation, when the viewer may open it on the Social Messages page. */
    private function visible(string $id, User $viewer): ?DirectConversation
    {
        return $this->access->visible($viewer, ConversationAccess::VIEW, $this->platform())->with('account')->find($id);
    }

    /** @return Builder<DirectConversation> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->access->visible($query->user, ConversationAccess::VIEW, $this->platform())
            ->whereNotNull('last_message_at')
            // "Unread" is the same counter the item shows.
            ->when($query->unread, fn (Builder $unread) => $unread->where('unread_count', '>', 0))
            ->when($query->owner === 'me', fn (Builder $mine) => $mine->where('owner_id', $query->membershipId))
            ->when($query->owner === 'unassigned', fn (Builder $nobody) => $nobody->whereNull('owner_id'))
            ->when($query->search !== '', fn (Builder $found) => $found->where(fn (Builder $match) => $match
                ->where('participant_name', 'like', Like::contains($query->search))
                ->orWhere('participant_id', 'like', Like::contains($query->search))
                ->orWhere('last_message_preview', 'like', Like::contains($query->search))));
    }

    /**
     * @param  Builder<DirectConversation>  $query
     * @return Builder<DirectConversation>
     */
    private function inState(Builder $query, InboxState $state): Builder
    {
        return match ($state) {
            InboxState::Unassigned => $query->where('status', DirectConversation::OPEN)->whereNull('owner_id'),
            InboxState::Active => $query->where('status', DirectConversation::OPEN)->whereNotNull('owner_id'),
            InboxState::Closed => $query->where('status', DirectConversation::CLOSED),
        };
    }

    private function stateOf(DirectConversation $conversation): InboxState
    {
        if ($conversation->status === DirectConversation::CLOSED) {
            return InboxState::Closed;
        }

        return $conversation->owner_id === null ? InboxState::Unassigned : InboxState::Active;
    }

    private function message(DirectMessage $message, DirectConversation $conversation): InboxMessage
    {
        $inbound = $message->direction === DirectMessage::IN;

        return new InboxMessage(
            id: $message->id,
            direction: $inbound ? InboxMessage::IN : InboxMessage::OUT,
            text: $message->text === null || $message->text === '' ? null : $message->text,
            at: CarbonImmutable::instance($message->sent_at)->utc(),
            authorName: $inbound ? null : ($message->sender->user->name ?? $conversation->account->name),
            status: match ($message->status) {
                DirectMessage::SENDING => 'queued',
                DirectMessage::SENT, DirectMessage::DELIVERED, DirectMessage::READ => $inbound ? null : $message->status,
                DirectMessage::DELETED => 'deleted',
                default => null,
            },
            attachmentLabel: self::attachmentLabel($message->attachment_type),
            attachmentKind: match ($message->attachment_type) {
                null => null,
                'image', 'video', 'audio' => $message->attachment_type,
                default => 'file',
            },
        );
    }

    /** What is attached, from the stored type only; the file itself is never fetched. */
    public static function attachmentLabel(?string $type): ?string
    {
        return match ($type) {
            null => null,
            'image' => __('Image'),
            'video' => __('Video'),
            'audio' => __('Audio'),
            'file' => __('File'),
            default => __('Attachment'),
        };
    }

    private function preview(DirectConversation $conversation): string
    {
        return mb_substr($conversation->last_message_preview ?? __('Attachment'), 0, 140);
    }

    private function lastActivity(DirectConversation $conversation): CarbonImmutable
    {
        return CarbonImmutable::instance($conversation->last_message_at ?? $conversation->created_at)->utc();
    }

    private function url(DirectConversation $conversation): string
    {
        return route('social.messages.index', ['conversation' => $conversation->id]);
    }
}
