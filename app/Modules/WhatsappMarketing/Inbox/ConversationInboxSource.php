<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Inbox;

use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxSourceDefinition;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Support\Like;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/** WhatsApp conversations in the unified inbox, narrowed as in this app's own inbox. */
final class ConversationInboxSource implements InboxSource
{
    public function __construct(private readonly ConversationAccess $access) {}

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: 'whatsapp_marketing.conversations', module: 'whatsapp_marketing', channel: 'whatsapp', label: 'WhatsApp', permission: Permissions::CONVERSATIONS_VIEW);
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

    /** @return Builder<Conversation> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->access->constrain(Conversation::query(), $query->user, Permissions::CONVERSATIONS_VIEW)
            ->whereNotNull('last_message_at')
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
