<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Support\Actions\LiveChatConversion;
use App\Modules\Support\Actions\LiveChatManager;
use App\Modules\Support\Actions\LiveChatSettings;
use App\Modules\Support\Enums\ChatDirection;
use App\Modules\Support\Enums\ChatStatus;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\ChatAccess;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Support "Live chat" page: website chats beside one open conversation, a composer and a close button
 * (ADR 0032). The same chats can be answered from the unified inbox; both go through {@see LiveChatManager}.
 *
 * Reading needs `support.tickets.view`, replying `support.tickets.reply`, closing `support.tickets.resolve`, each
 * narrowed by {@see ChatAccess}. A chat outside the member's reach is a 404. Saving the visitor as a shared contact
 * needs `core.contacts.manage`, turning the chat into a ticket `support.tickets.create`, each with the chat's view
 * reach ({@see LiveChatConversion}).
 */
final class LiveChatController
{
    public const int PAGE = 25;

    private const string ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    private const array STATES = ['open', 'unassigned', 'mine', 'closed', 'all'];

    public function __construct(
        private readonly SupportControllerSupport $support,
        private readonly ChatAccess $access,
        private readonly AccessChecker $checker,
        private readonly LiveChatManager $chats,
        private readonly LiveChatSettings $settings,
        private readonly LiveChatConversion $conversion,
        private readonly RecordAccess $records,
        private readonly TicketAccess $tickets,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $input = $request->validate([
            'state' => ['nullable', Rule::in(self::STATES)],
            'search' => ['nullable', 'string', 'max:100'],
            'open' => ['nullable', 'string', 'regex:'.self::ULID],
            'messages' => ['nullable', 'integer', 'min:1', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);
        $state = $input['state'] ?? 'open';
        $search = trim((string) ($input['search'] ?? ''));
        $open = $input['open'] ?? null;
        $limit = (int) ($input['messages'] ?? 50);

        return Inertia::render('Modules/Support/Pages/LiveChat/Index', [
            'filters' => ['state' => $state, 'search' => $search, 'open' => $open],
            'enabled' => $this->settings->find()->enabled ?? false,
            'canManageSettings' => $this->checker->allows($actor, SupportPermissions::SETTINGS_MANAGE),
            // Before the list: opening a conversation marks it read, and the list then shows it read.
            'thread' => $open === null ? null : fn (): array => $this->thread($actor, (string) $open, $limit),
            'conversations' => fn (): array => $this->list($actor, $state, $search),
        ]);
    }

    public function reply(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_REPLY, $conversation);
        $input = $request->validate(['text' => ['required', 'string', 'max:4000']]);

        $this->chats->reply($conversation, $input['text'], $this->support->membership($actor));

        return back();
    }

    public function close(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_RESOLVE, $conversation);

        $this->chats->close($conversation, $this->support->membership($actor));
        Toast::success(__('Chat closed.'));

        return back();
    }

    /** Saves the visitor as a shared contact (or links the existing one with the same email address). */
    public function saveContact(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_VIEW, $conversation);

        $result = $this->conversion->saveContact($conversation, $this->support->membership($actor));

        Toast::success(match (true) {
            ! $result['linked'] => __('This chat is already linked to a contact.'),
            $result['created'] => __('Contact saved and linked to the chat.'),
            default => __('Linked to the existing contact with this email address.'),
        });

        return back();
    }

    /** Turns the chat into a support ticket, once; optionally closes the chat. */
    public function createTicket(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_VIEW, $conversation);
        $input = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'queue_id' => ['nullable', 'string', 'size:26'],
            'close' => ['nullable', 'boolean'],
        ]);
        $close = (bool) ($input['close'] ?? false);

        if ($close) {
            // Closing in the same step needs what the close button needs.
            $this->access->authorize($actor, SupportPermissions::TICKETS_RESOLVE, $conversation);
        }

        $result = $this->conversion->createTicket($conversation, [
            'subject' => $input['subject'] ?? null,
            'priority' => $input['priority'] ?? null,
            'queue_id' => $input['queue_id'] ?? null,
        ], $this->support->membership($actor), $close);

        Toast::success($result['created']
            ? __('Ticket :number created from the chat.', ['number' => $result['ticket']->number])
            : __('This chat already has ticket :number.', ['number' => $result['ticket']->number]));

        return back();
    }

    /** @return array{items: list<array<string, mixed>>, next: string|null, previous: string|null} */
    private function list(User $actor, string $state, string $search): array
    {
        $membershipId = $this->checker->membership($actor)?->id;
        $query = $this->access->constrain(ChatConversation::query(), $actor, SupportPermissions::TICKETS_VIEW)
            ->whereNotNull('last_message_at')
            ->when($state === 'open', fn (Builder $open) => $open->where('status', ChatStatus::Open->value))
            ->when($state === 'unassigned', fn (Builder $nobody) => $nobody->where('status', ChatStatus::Open->value)->whereNull('assignee_membership_id'))
            ->when($state === 'mine', fn (Builder $mine) => $mine->where('status', ChatStatus::Open->value)->where('assignee_membership_id', $membershipId))
            ->when($state === 'closed', fn (Builder $closed) => $closed->where('status', ChatStatus::Closed->value))
            ->when($search !== '', fn (Builder $found) => $found->where(fn (Builder $match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search))
                ->orWhere('page_url', 'like', Like::contains($search))));

        $page = ChatConversation::withPreview($query)
            ->with('assignee.user:id,name')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE)
            ->withQueryString();

        return [
            'items' => array_values(array_map(fn (ChatConversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->displayName(),
                'email' => $conversation->email,
                'preview' => $conversation->preview === null ? null : mb_substr($conversation->preview, 0, 140),
                'status' => $conversation->status->value,
                'assignee' => $conversation->assignee?->user?->name,
                'unread' => $conversation->unread_count,
                'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            ], $page->items())),
            'next' => $page->nextCursor()?->encode(),
            'previous' => $page->previousCursor()?->encode(),
        ];
    }

    /** @return array<string, mixed> */
    private function thread(User $actor, string $id, int $limit): array
    {
        $conversation = $this->access->constrain(ChatConversation::query(), $actor, SupportPermissions::TICKETS_VIEW)
            ->with('assignee.user:id,name')
            ->find($id);

        abort_if($conversation === null, 404);

        // Opening (or polling) the conversation reads it, as in the unified inbox.
        $this->chats->markRead($conversation);

        $rows = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('sentBy.user:id,name')
            ->orderByDesc('seq')
            ->limit($limit + 1)
            ->get();
        $mayReply = $this->access->allows($actor, SupportPermissions::TICKETS_REPLY, $conversation);
        $closed = $conversation->isClosed();

        return [
            'id' => $conversation->id,
            'title' => $conversation->displayName(),
            'name' => $conversation->name,
            'email' => $conversation->email,
            'pageUrl' => $conversation->page_url,
            'status' => $conversation->status->value,
            'assignee' => $conversation->assignee?->user?->name,
            'startedAt' => $conversation->created_at->toIso8601String(),
            'closedAt' => $conversation->closed_at?->toIso8601String(),
            'mayReply' => $mayReply,
            'canReply' => $mayReply && ! $closed,
            'replyBlockedReason' => $mayReply && $closed ? LiveChatManager::closedReason() : null,
            'canClose' => ! $closed && $this->access->allows($actor, SupportPermissions::TICKETS_RESOLVE, $conversation),
            ...$this->links($actor, $conversation),
            'hasOlder' => $rows->count() > $limit,
            'limit' => $limit,
            'messages' => $rows->take($limit)->reverse()->map(fn (ChatMessage $message): array => [
                'id' => $message->id,
                'direction' => $message->direction->value,
                'text' => $message->text,
                'at' => $message->created_at->toIso8601String(),
                'author' => $message->direction === ChatDirection::Out ? ($message->sentBy->user->name ?? __('Agent')) : null,
            ])->values()->all(),
        ];
    }

    /**
     * The chat's contact and ticket, and what the member may do about them. A linked contact or ticket outside the
     * member's reach is shown without its name or link.
     *
     * @return array<string, mixed>
     */
    private function links(User $actor, ChatConversation $conversation): array
    {
        $contact = $conversation->contact_id === null ? null : Contact::query()->find($conversation->contact_id);
        $ticket = $conversation->ticket_id === null ? null : Ticket::query()->find($conversation->ticket_id);
        $seesContact = $contact !== null && $this->records->allows($actor, ContactController::VIEW, $contact);
        $hasIdentity = $conversation->name !== null || $conversation->email !== null;
        $mayCreateTicket = $ticket === null && $this->checker->allows($actor, SupportPermissions::TICKETS_CREATE);
        $ticketable = $contact !== null || $conversation->email !== null;

        return [
            'contact' => $contact === null ? null : [
                'name' => $seesContact ? $contact->fullName() : null,
                'search' => $seesContact ? ($contact->email ?? $contact->fullName()) : null,
            ],
            'canSaveContact' => $contact === null && $hasIdentity && $this->checker->allows($actor, ContactController::MANAGE),
            'ticket' => $ticket === null ? null : [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'canOpen' => $this->tickets->allows($actor, SupportPermissions::TICKETS_VIEW, $ticket),
            ],
            'canCreateTicket' => $mayCreateTicket && $ticketable,
            'ticketNeedsContact' => $mayCreateTicket && ! $ticketable,
            'ticketForm' => ! ($mayCreateTicket && $ticketable) ? null : [
                'subject' => $this->conversion->defaultSubject($conversation),
                'priorities' => array_map(static fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases()),
                'queues' => array_map(static fn (array $queue): array => ['id' => $queue['id'], 'name' => $queue['name']], $this->support->queues()),
            ],
        ];
    }
}
