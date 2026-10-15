<?php

declare(strict_types=1);

namespace App\Modules\Support\Inbox;

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
use App\Modules\Support\Actions\LiveChatManager;
use App\Modules\Support\Enums\ChatDirection;
use App\Modules\Support\Enums\ChatStatus;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Support\ChatAccess;
use App\Modules\Support\Support\SupportPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Website chats in the unified inbox (ADR 0032), read and answered there too (ADR 0030). Narrowed exactly as the
 * Support "Live chat" page ({@see ChatAccess}); replies, reads and the reopen rule go through
 * {@see LiveChatManager}, the same code path as that page.
 *
 * States: open and unassigned is `unassigned`, open and assigned is `active`, closed is `closed`. Unread is the
 * number of visitor messages since a member last read or answered the chat.
 */
final class LiveChatInboxSource implements InboxReplies, InboxSource, MarksInboxRead
{
    /** The source key; the app's realtime signals name it too (ADR 0035). */
    public const string KEY = 'support.live_chat';

    public function __construct(
        private readonly ChatAccess $access,
        private readonly AccessChecker $checker,
        private readonly LiveChatManager $chats,
    ) {}

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: self::KEY, module: 'support', channel: 'web', label: 'Website chat', permission: SupportPermissions::TICKETS_VIEW);
    }

    public function items(InboxQuery $query, ?InboxCursor $after = null): array
    {
        $conversations = ChatConversation::withPreview($this->filtered($query))
            ->where(function (Builder $states) use ($query): void {
                foreach ($query->states as $state) {
                    $states->orWhere(fn (Builder $one) => $this->inState($one, $state));
                }
            })
            ->when($after !== null, fn (Builder $older) => $older->where(fn (Builder $cursor) => $cursor
                ->where('last_message_at', '<', $after?->at)
                ->orWhere(fn (Builder $same) => $same->where('last_message_at', $after?->at)->where('id', '<', $after?->id))))
            ->with('assignee.user:id,name')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1)
            ->get();

        return $conversations->map(fn (ChatConversation $conversation): InboxItem => new InboxItem(
            id: $conversation->id,
            title: $conversation->displayName(),
            preview: $conversation->preview === null ? null : mb_substr($conversation->preview, 0, 140),
            at: ($conversation->last_message_at ?? $conversation->created_at)->toImmutable()->utc(),
            state: $this->stateOf($conversation),
            url: route('support.live_chat.index', ['open' => $conversation->id]),
            contactId: $conversation->contact_id,
            ownerName: $conversation->assignee?->user?->name,
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

        $rows = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('sentBy.user:id,name')
            ->orderByDesc('seq')
            ->limit($limit + 1)
            ->get();
        $replyable = $this->access->allows($viewer, SupportPermissions::TICKETS_REPLY, $conversation);
        $blocked = $replyable && $conversation->isClosed() ? LiveChatManager::closedReason() : null;

        return new InboxThread(
            sourceKey: $this->definition()->key,
            id: $conversation->id,
            title: $conversation->displayName(),
            channel: $this->definition()->channel,
            contactId: $conversation->contact_id,
            url: route('support.live_chat.index', ['open' => $conversation->id]),
            canReply: $replyable && $blocked === null,
            replyBlockedReason: $blocked,
            messages: $rows->take($limit)->reverse()->map(fn (ChatMessage $message): InboxMessage => $this->message($message))->values()->all(),
            hasOlder: $rows->count() > $limit,
            subtitle: $this->subtitle($conversation),
        );
    }

    public function reply(string $id, string $text, User $viewer): InboxMessage
    {
        $conversation = $this->visible($id, $viewer) ?? throw new NotFoundHttpException;

        // The same record reach as the Live chat page's reply route: outside it the chat is not disclosed.
        if (! $this->access->allows($viewer, SupportPermissions::TICKETS_REPLY, $conversation)) {
            throw new NotFoundHttpException;
        }

        $membership = $this->checker->membership($viewer) ?? throw new AccessDeniedHttpException;

        // A refusal (closed chat, empty or too long text) is a ValidationException on `text`, as the inbox expects.
        $message = $this->chats->reply($conversation, $text, $membership);

        return $this->message($message->load('sentBy.user:id,name'));
    }

    public function replyPermission(): string
    {
        return SupportPermissions::TICKETS_REPLY;
    }

    public function markRead(string $id, User $viewer): void
    {
        $conversation = $this->visible($id, $viewer);

        if ($conversation !== null) {
            $this->chats->markRead($conversation);
        }
    }

    /** The conversation, when the viewer may open it on the Live chat page. */
    private function visible(string $id, User $viewer): ?ChatConversation
    {
        return $this->access->constrain(ChatConversation::query(), $viewer, SupportPermissions::TICKETS_VIEW)->find($id);
    }

    private function subtitle(ChatConversation $conversation): ?string
    {
        $parts = array_filter([
            $conversation->name !== null ? $conversation->email : null,
            $conversation->page_url,
        ], static fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function message(ChatMessage $message): InboxMessage
    {
        $inbound = $message->direction === ChatDirection::In;

        return new InboxMessage(
            id: $message->id,
            direction: $inbound ? InboxMessage::IN : InboxMessage::OUT,
            text: $message->text,
            at: CarbonImmutable::instance($message->created_at)->utc(),
            authorName: $inbound ? null : ($message->sentBy->user->name ?? __('Agent')),
            status: $inbound ? null : 'sent',
        );
    }

    /** @return Builder<ChatConversation> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->access->constrain(ChatConversation::query(), $query->user, SupportPermissions::TICKETS_VIEW)
            ->whereNotNull('last_message_at')
            // "Unread" is the same counter the item shows.
            ->when($query->unread, fn (Builder $unread) => $unread->where('unread_count', '>', 0))
            ->when($query->owner === 'me', fn (Builder $mine) => $mine->where('assignee_membership_id', $query->membershipId))
            ->when($query->owner === 'unassigned', fn (Builder $nobody) => $nobody->whereNull('assignee_membership_id'))
            ->when($query->search !== '', fn (Builder $found) => $found->where(fn (Builder $match) => $match
                ->where('name', 'like', Like::contains($query->search))
                ->orWhere('email', 'like', Like::contains($query->search))
                ->orWhere('page_url', 'like', Like::contains($query->search))));
    }

    /**
     * @param  Builder<ChatConversation>  $query
     * @return Builder<ChatConversation>
     */
    private function inState(Builder $query, InboxState $state): Builder
    {
        return match ($state) {
            InboxState::Unassigned => $query->where('status', ChatStatus::Open->value)->whereNull('assignee_membership_id'),
            InboxState::Active => $query->where('status', ChatStatus::Open->value)->whereNotNull('assignee_membership_id'),
            InboxState::Closed => $query->where('status', ChatStatus::Closed->value),
        };
    }

    private function stateOf(ChatConversation $conversation): InboxState
    {
        if ($conversation->isClosed()) {
            return InboxState::Closed;
        }

        return $conversation->assignee_membership_id === null ? InboxState::Unassigned : InboxState::Active;
    }
}
