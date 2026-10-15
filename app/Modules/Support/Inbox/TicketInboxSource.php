<?php

declare(strict_types=1);

namespace App\Modules\Support\Inbox;

use App\Core\Access\AccessChecker;
use App\Core\Communication\Contracts\InboxReplies;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxSourceDefinition;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Communication\Inbox\InboxThread;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Support tickets in the unified inbox, narrowed as in the ticket list. Resolved, closed and merged are "closed".
 *
 * A ticket is also read and answered there (ADR 0030): the conversation is the requester's messages and the public
 * replies, never the internal notes, which stay on the ticket page. A reply is a public reply through
 * {@see TicketManager::addMessage()}, the same code path as the ticket page, so it reaches the requester the same way.
 */
final class TicketInboxSource implements InboxReplies, InboxSource
{
    /** The source key; the app's realtime signals name it too (ADR 0035). */
    public const string KEY = 'support.tickets';

    public function __construct(
        private readonly TicketAccess $access,
        private readonly AccessChecker $checker,
        private readonly TicketManager $tickets,
    ) {}

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: self::KEY, module: 'support', channel: 'support', label: 'Support tickets', permission: SupportPermissions::TICKETS_VIEW);
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
            title: $this->title($ticket),
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

    public function thread(string $id, User $viewer, int $limit = self::DEFAULT_LIMIT): ?InboxThread
    {
        $ticket = $this->visible($id, $viewer);

        if ($ticket === null) {
            return null;
        }

        $rows = Message::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('kind', [MessageKind::PublicReply->value, MessageKind::CustomerReply->value])
            ->with('attachments:id,message_id,original_name,mime_type')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $replyable = $this->access->allows($viewer, SupportPermissions::TICKETS_REPLY, $ticket);
        $blocked = $replyable ? $this->blockedReason($ticket) : null;

        return new InboxThread(
            sourceKey: $this->definition()->key,
            id: $ticket->id,
            title: $this->title($ticket),
            channel: $this->definition()->channel,
            contactId: $ticket->contact_id,
            url: route('support.tickets.show', $ticket),
            canReply: $replyable && $blocked === null,
            replyBlockedReason: $blocked,
            messages: $rows->take($limit)->reverse()->map(fn (Message $message): InboxMessage => $this->message($message))->values()->all(),
            hasOlder: $rows->count() > $limit,
            subtitle: mb_substr($ticket->number.' · '.$ticket->subject, 0, 140),
        );
    }

    public function reply(string $id, string $text, User $viewer): InboxMessage
    {
        $ticket = $this->visible($id, $viewer) ?? throw new NotFoundHttpException;

        // The same record reach as the ticket page's reply route: outside it the ticket is not disclosed.
        if (! $this->access->allows($viewer, SupportPermissions::TICKETS_REPLY, $ticket)) {
            throw new NotFoundHttpException;
        }

        $membership = $this->checker->membership($viewer) ?? throw new AccessDeniedHttpException;

        try {
            $message = $this->tickets->addMessage($ticket, MessageKind::PublicReply, $text, $membership);
        } catch (ValidationException $refused) {
            // The app reports on its own fields (`body`, `ticket`); the inbox composer's field is `text`.
            throw ValidationException::withMessages(['text' => array_values(array_map('strval', Arr::flatten($refused->errors())))]);
        }

        return $this->message($message->load('attachments:id,message_id,original_name,mime_type'));
    }

    public function replyPermission(): string
    {
        return SupportPermissions::TICKETS_REPLY;
    }

    /** The ticket, when the viewer may open it on the ticket page. */
    private function visible(string $id, User $viewer): ?Ticket
    {
        return $this->access->constrain(Ticket::query(), $viewer, SupportPermissions::TICKETS_VIEW)
            ->with('contact:id,first_name,last_name')
            ->find($id);
    }

    /** Why a public reply cannot be written now; the same rules {@see TicketManager::addMessage()} enforces. */
    private function blockedReason(Ticket $ticket): ?string
    {
        return match ($ticket->status) {
            TicketStatus::Merged => __('This ticket was merged into another ticket and can no longer be changed.'),
            TicketStatus::Closed => __('Reopen the ticket before replying to the customer.'),
            default => null,
        };
    }

    private function message(Message $message): InboxMessage
    {
        $inbound = $message->kind === MessageKind::CustomerReply;
        $first = $message->attachments->first();
        $more = $message->attachments->count() - 1;

        return new InboxMessage(
            id: $message->id,
            direction: $inbound ? InboxMessage::IN : InboxMessage::OUT,
            text: $message->body,
            at: CarbonImmutable::instance($message->created_at)->utc(),
            authorName: $message->author_name ?? ($inbound ? null : __('Agent')),
            status: $inbound ? null : 'sent',
            attachmentLabel: $first === null ? null : $first->original_name.($more > 0 ? ' +'.$more : ''),
            attachmentKind: $first === null ? null : $this->attachmentKind($first),
        );
    }

    private function attachmentKind(Attachment $attachment): string
    {
        return match (true) {
            str_starts_with($attachment->mime_type, 'image/') => 'image',
            str_starts_with($attachment->mime_type, 'video/') => 'video',
            str_starts_with($attachment->mime_type, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function title(Ticket $ticket): string
    {
        return $ticket->contact?->fullName() ?? $ticket->requester_name ?? $ticket->requester_email ?? $ticket->number;
    }

    /** @return Builder<Ticket> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->access->constrain(Ticket::query(), $query->user, SupportPermissions::TICKETS_VIEW)
            ->whereNotNull('last_activity_at')
            // "Unread" is the item's own rule ({@see awaitsReply()}): the customer wrote after the last agent reply.
            ->when($query->unread, fn (Builder $awaiting) => $awaiting
                ->whereNotNull('last_customer_message_at')
                ->where(fn (Builder $newer) => $newer
                    ->whereNull('last_agent_message_at')
                    ->orWhereColumn('last_customer_message_at', '>', 'last_agent_message_at')))
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
