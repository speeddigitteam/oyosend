<?php

declare(strict_types=1);

namespace App\Modules\Support\Inbox;

use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxSourceDefinition;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Support\Like;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Illuminate\Database\Eloquent\Builder;

/** Support tickets in the unified inbox, narrowed as in the ticket list. Resolved, closed and merged are "closed". */
final class TicketInboxSource implements InboxSource
{
    public function __construct(private readonly TicketAccess $access) {}

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: 'support.tickets', module: 'support', channel: 'support', label: 'Support tickets', permission: SupportPermissions::TICKETS_VIEW);
    }

    public function items(InboxQuery $query, ?InboxCursor $after = null): array
    {
        $tickets = $this->filtered($query)
            ->where(function (Builder $states) use ($query): void {
                foreach ($query->states as $state) {
                    $states->orWhere(fn (Builder $one) => $this->inState($one, $state));
                }
            })
            ->when($after !== null, fn (Builder $older) => $older->where(fn (Builder $cursor) => $cursor
                ->where('last_activity_at', '<', $after?->at)
                ->orWhere(fn (Builder $same) => $same->where('last_activity_at', $after?->at)->where('id', '<', $after?->id))))
            ->with(['contact:id,first_name,last_name', 'owner.user:id,name'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1)
            ->get();

        return $tickets->map(fn (Ticket $ticket): InboxItem => new InboxItem(
            id: $ticket->id,
            title: $ticket->contact?->fullName() ?? $ticket->requester_name ?? $ticket->requester_email ?? $ticket->number,
            preview: mb_substr($ticket->number.' · '.$ticket->subject, 0, 140),
            at: ($ticket->last_activity_at ?? $ticket->created_at)->toImmutable()->utc(),
            state: $this->stateOf($ticket),
            url: route('support.tickets.show', $ticket),
            contactId: $ticket->contact_id,
            ownerName: $ticket->owner?->user?->name,
            unread: $this->awaitsReply($ticket) ? 1 : 0,
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

    /** @return Builder<Ticket> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->access->constrain(Ticket::query(), $query->user, SupportPermissions::TICKETS_VIEW)
            ->whereNotNull('last_activity_at')
            ->when($query->owner === 'me', fn (Builder $mine) => $mine->where('owner_id', $query->membershipId))
            ->when($query->owner === 'unassigned', fn (Builder $nobody) => $nobody->whereNull('owner_id'))
            ->when($query->search !== '', fn (Builder $found) => $found->where(fn (Builder $match) => $match
                ->where('subject', 'like', Like::contains($query->search))
                ->orWhere('number', 'like', Like::contains($query->search))
                ->orWhere('requester_email', 'like', Like::contains($query->search))
                ->orWhere('requester_name', 'like', Like::contains($query->search))));
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private function inState(Builder $query, InboxState $state): Builder
    {
        return match ($state) {
            InboxState::Unassigned => $query->whereIn('status', self::activeStatuses())->whereNull('owner_id'),
            InboxState::Active => $query->whereIn('status', self::activeStatuses())->whereNotNull('owner_id'),
            InboxState::Closed => $query->whereNotIn('status', self::activeStatuses()),
        };
    }

    private function stateOf(Ticket $ticket): InboxState
    {
        if (! in_array($ticket->status->value, self::activeStatuses(), true)) {
            return InboxState::Closed;
        }

        return $ticket->owner_id === null ? InboxState::Unassigned : InboxState::Active;
    }

    private function awaitsReply(Ticket $ticket): bool
    {
        return $ticket->last_customer_message_at !== null
            && ($ticket->last_agent_message_at === null || $ticket->last_customer_message_at->gt($ticket->last_agent_message_at));
    }

    /** @return list<string> */
    private static function activeStatuses(): array
    {
        return array_map(static fn (TicketStatus $status): string => $status->value, [TicketStatus::New, TicketStatus::Open, TicketStatus::WaitingCustomer, TicketStatus::OnHold]);
    }
}
