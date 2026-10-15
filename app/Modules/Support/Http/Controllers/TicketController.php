<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Support\Actions\AttachmentStore;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\TicketStoreRequest;
use App\Modules\Support\Http\Requests\TicketUpdateRequest;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\CannedResponse;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\Tag;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketTag;
use App\Modules\Support\Support\ChatAccess;
use App\Modules\Support\Support\PlaceholderRenderer;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TicketController
{
    public function __construct(
        private readonly TicketAccess $access,
        private readonly TicketManager $tickets,
        private readonly AttachmentStore $attachments,
        private readonly SupportControllerSupport $support,
        private readonly SupportSettings $settings,
        private readonly ChatAccess $chats,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'all', ...array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::cases())])],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'queue_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'assignee' => ['nullable', 'string', 'max:26'],
            'contact_id' => ['nullable', 'string', 'size:26'],
            'company_id' => ['nullable', 'string', 'size:26'],
            'tag' => ['nullable', 'string', 'max:60'],
            'sla' => ['nullable', Rule::in(['breached', 'paused'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? 'active';

        $tickets = $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_VIEW)
            ->with(SupportControllerSupport::LIST_RELATIONS)
            ->when($status === 'active', fn ($query) => $query->whereIn('status', TicketStatus::activeValues()))
            ->when($status !== 'active' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search): void {
                $like = Like::contains($search);
                $match->where('number', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhere('requester_name', 'like', $like)
                    ->orWhere('requester_email', 'like', $like)
                    ->orWhereIn('contact_id', Contact::query()
                        ->where(fn ($contact) => $contact->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like))
                        ->select('id'));
            }))
            ->when(isset($filters['priority']), fn ($query) => $query->where('priority', $filters['priority']))
            ->when(isset($filters['queue_id']), fn ($query) => $query->where('queue_id', $filters['queue_id']))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['contact_id']), fn ($query) => $query->where('contact_id', $filters['contact_id']))
            ->when(isset($filters['company_id']), fn ($query) => $query->where('company_id', $filters['company_id']))
            ->when(($filters['assignee'] ?? null) === 'me', fn ($query) => $query->where('owner_id', $membership->id))
            ->when(($filters['assignee'] ?? null) === 'unassigned', fn ($query) => $query->whereNull('owner_id'))
            ->when(isset($filters['assignee']) && ! in_array($filters['assignee'], ['me', 'unassigned'], true), fn ($query) => $query->where('owner_id', $filters['assignee']))
            ->when(isset($filters['tag']), fn ($query) => $query->whereIn('id', TicketTag::query()
                ->whereIn('tag_id', Tag::query()->where('name', $filters['tag'])->select('id'))->select('ticket_id')))
            ->when(($filters['sla'] ?? null) === 'breached', fn ($query) => $query->where(fn ($match) => $match
                ->where(fn ($first) => $first->whereNull('first_responded_at')->whereNotNull('first_response_breached_at'))
                ->orWhere(fn ($resolution) => $resolution->whereNull('resolved_at')->whereNotNull('resolution_breached_at'))))
            ->when(($filters['sla'] ?? null) === 'paused', fn ($query) => $query->whereNotNull('sla_paused_at'))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ticket $ticket): array => $this->support->summary($ticket));

        $base = fn () => $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_VIEW)
            ->whereIn('status', TicketStatus::activeValues());

        return Inertia::render('Modules/Support/Pages/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'priority' => $filters['priority'] ?? null,
                'queueId' => $filters['queue_id'] ?? null,
                'categoryId' => $filters['category_id'] ?? null,
                'assignee' => $filters['assignee'] ?? null,
                'contactId' => $filters['contact_id'] ?? null,
                'companyId' => $filters['company_id'] ?? null,
                'tag' => $filters['tag'] ?? null,
                'sla' => $filters['sla'] ?? null,
            ],
            'counts' => [
                'active' => $base()->count(),
                'mine' => $base()->where('owner_id', $membership->id)->count(),
                'unassigned' => $base()->whereNull('owner_id')->count(),
                'breached' => $base()->where(fn ($match) => $match
                    ->where(fn ($first) => $first->whereNull('first_responded_at')->whereNotNull('first_response_breached_at'))
                    ->orWhere(fn ($resolution) => $resolution->whereNull('resolved_at')->whereNotNull('resolution_breached_at')))->count(),
            ],
            'options' => $this->support->formOptions($actor, withCustomers: false),
            'canCreate' => $actor->can(SupportPermissions::TICKETS_CREATE),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Support/Pages/Tickets/Create', [
            'form' => [
                ...$this->support->formOptions($actor),
                'tags' => Tag::query()->orderBy('name')->limit(200)->pluck('name')->all(),
                'defaultPriority' => $this->settings->current()->default_priority->value,
                'canAssign' => $actor->can(SupportPermissions::TICKETS_ASSIGN),
                'maxAttachments' => AttachmentStore::MAX_FILES,
            ],
            'prefill' => [
                'contactId' => $request->query('contact_id'),
                'companyId' => $request->query('company_id'),
            ],
        ]);
    }

    public function store(TicketStoreRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $input = $request->validated();

        if (isset($input['assignee_id']) && $input['assignee_id'] !== $membership->id) {
            // Handing a new ticket to someone else is an assignment like any other.
            if (! $actor->can(SupportPermissions::TICKETS_ASSIGN) || ! $this->access->hasAll($actor, SupportPermissions::TICKETS_ASSIGN)) {
                throw ValidationException::withMessages(['assignee_id' => __('You cannot assign tickets to other people.')]);
            }
        }

        $ticket = $this->tickets->create(
            [...$input, ...$this->support->customer($input, $actor)],
            $membership,
            TicketChannel::from((string) ($input['channel'] ?? TicketChannel::Web->value)),
            files: $request->file('attachments', []),
        );
        Toast::success(__('Ticket :number created.', ['number' => $ticket->number]));

        return redirect()->route('support.tickets.show', $ticket);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_VIEW, $ticket);
        $ticket->load([...SupportControllerSupport::LIST_RELATIONS, 'department:id,name', 'branch:id,name', 'slaPolicy:id,name', 'creator.user:id,name']);

        $messages = Message::query()
            ->where('ticket_id', $ticket->id)
            ->with(['author.user:id,name', 'attachments'])
            ->orderBy('created_at')->orderBy('id')
            ->limit(1000)->get();
        $events = TicketEvent::query()->where('ticket_id', $ticket->id)->with('actor.user:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $eventNames = $this->resolveEventNames($events);
        $surveys = $ticket->surveys()->limit(10)->get();
        $can = fn (string $permission): bool => $this->access->allows($actor, $permission, $ticket);
        $merged = $ticket->status === TicketStatus::Merged;
        $finished = $ticket->status->isFinished();
        $canAll = $this->access->hasAll($actor, SupportPermissions::TICKETS_ASSIGN);
        $mergeCandidates = ! $merged && $can(SupportPermissions::TICKETS_MERGE)
            ? $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_MERGE)
                ->whereIn('status', TicketStatus::activeValues())->whereKeyNot($ticket->id)
                ->where(function ($match) use ($ticket): void {
                    $match->whereRaw('1 = 0');

                    if ($ticket->contact_id !== null) {
                        $match->orWhere('contact_id', $ticket->contact_id);
                    }

                    if ($ticket->company_id !== null) {
                        $match->orWhere('company_id', $ticket->company_id);
                    }

                    if ($ticket->requester_email !== null) {
                        $match->orWhere('requester_email', $ticket->requester_email);
                    }
                })
                ->latest('last_activity_at')->limit(10)->get(['id', 'number', 'subject'])
                ->map(fn (Ticket $candidate): array => ['id' => $candidate->id, 'number' => $candidate->number, 'subject' => $candidate->subject])->all()
            : [];

        return Inertia::render('Modules/Support/Pages/Tickets/Show', [
            'ticket' => [
                ...$this->support->summary($ticket),
                'department' => $ticket->department === null ? null : ['id' => $ticket->department->id, 'name' => $ticket->department->name],
                'branch' => $ticket->branch === null ? null : ['id' => $ticket->branch->id, 'name' => $ticket->branch->name],
                'slaPolicy' => $ticket->slaPolicy === null ? null : ['id' => $ticket->slaPolicy->id, 'name' => $ticket->slaPolicy->name],
                'requesterName' => $ticket->requester_name,
                'requesterEmail' => $ticket->requester_email,
                'resolvedAt' => $ticket->resolved_at?->toIso8601String(),
                'closedAt' => $ticket->closed_at?->toIso8601String(),
                'lastCustomerMessageAt' => $ticket->last_customer_message_at?->toIso8601String(),
                'lastAgentMessageAt' => $ticket->last_agent_message_at?->toIso8601String(),
                'reopenedCount' => $ticket->reopened_count,
                'createdBy' => $ticket->creator?->user->name,
                'mergedInto' => $ticket->merged_into_id === null ? null : $this->reference($actor, $ticket->merged_into_id),
                'splitFrom' => $ticket->split_from_id === null ? null : $this->reference($actor, $ticket->split_from_id),
                // The website chat this ticket was made from, when the member reaches that chat (ADR 0032 amendment).
                'fromChat' => $this->chats->constrain(ChatConversation::query(), $actor, SupportPermissions::TICKETS_VIEW)
                    ->where('ticket_id', $ticket->id)->value('id'),
                'mergedTickets' => $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_VIEW)->where('merged_into_id', $ticket->id)->orderBy('number')->get(['id', 'number', 'subject'])
                    ->map(fn (Ticket $merged): array => ['id' => $merged->id, 'number' => $merged->number, 'subject' => $merged->subject])->all(),
                'splitTickets' => $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_VIEW)->where('split_from_id', $ticket->id)->orderBy('number')->get(['id', 'number', 'subject'])
                    ->map(fn (Ticket $split): array => ['id' => $split->id, 'number' => $split->number, 'subject' => $split->subject])->all(),
            ],
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'kind' => $message->kind->value,
                'isInternal' => ! $message->kind->isPublic(),
                'isCustomer' => $message->kind === MessageKind::CustomerReply,
                'author' => [
                    'name' => $message->author?->user->name ?? $message->author_name,
                    'email' => $message->author_email,
                    'membershipId' => $message->author_id,
                ],
                'body' => $message->body,
                'createdAt' => $message->created_at->toIso8601String(),
                'attachments' => $message->attachments->map(fn (Attachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'size' => $attachment->size,
                    'mimeType' => $attachment->mime_type,
                ])->all(),
            ])->all(),
            'events' => $events->map(fn (TicketEvent $event): array => [
                'id' => $event->id,
                'kind' => $event->kind->value,
                'actor' => $event->actor?->user->name,
                'data' => ($event->data ?? []) + $this->eventNames($event, $eventNames),
                'createdAt' => $event->created_at->toIso8601String(),
            ])->all(),
            'surveys' => $surveys->map(fn ($survey): array => [
                'id' => $survey->id,
                'status' => $survey->status->value,
                'rating' => $survey->rating,
                'comment' => $survey->status === SurveyStatus::Answered ? $survey->comment : null,
                'requestedAt' => $survey->created_at->toIso8601String(),
                'answeredAt' => $survey->answered_at?->toIso8601String(),
                'expiresAt' => $survey->expires_at->toIso8601String(),
            ])->all(),
            'abilities' => [
                'edit' => ! $merged && $can(SupportPermissions::TICKETS_EDIT),
                'reply' => ! $merged && $can(SupportPermissions::TICKETS_REPLY),
                'publicReply' => ! $merged && $ticket->status !== TicketStatus::Closed && $can(SupportPermissions::TICKETS_REPLY),
                'assign' => ! $merged && $can(SupportPermissions::TICKETS_ASSIGN),
                'assignOthers' => ! $merged && $canAll && $can(SupportPermissions::TICKETS_ASSIGN),
                'resolve' => ! $merged && $can(SupportPermissions::TICKETS_RESOLVE),
                'reopen' => $finished && $can(SupportPermissions::TICKETS_RESOLVE),
                'merge' => ! $merged && $can(SupportPermissions::TICKETS_MERGE),
                'split' => ! $merged && $can(SupportPermissions::TICKETS_MERGE),
                'survey' => $finished && $can(SupportPermissions::TICKETS_RESOLVE),
                'useCanned' => ! $merged && $actor->can(SupportPermissions::CANNED_VIEW) && $can(SupportPermissions::TICKETS_REPLY),
            ],
            'options' => [
                // Customer pickers are only needed (and only sent) to someone who can change the requester.
                ...$this->support->formOptions($actor, withCustomers: ! $merged && $can(SupportPermissions::TICKETS_EDIT)),
                'tags' => Tag::query()->orderBy('name')->limit(200)->pluck('name')->all(),
                'statusTargets' => $this->statusTargets($ticket->status),
                'maxAttachments' => AttachmentStore::MAX_FILES,
            ],
            'canned' => $actor->can(SupportPermissions::CANNED_VIEW) && ! $merged
                ? CannedResponse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (CannedResponse $response): array => ['id' => $response->id, 'name' => $response->name])->all()
                : [],
            'mergeCandidates' => $mergeCandidates,
        ]);
    }

    public function update(TicketUpdateRequest $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_EDIT, $ticket);
        $input = $request->validated();
        $customer = $this->support->customer($input, $actor);

        if (! array_key_exists('contact_id', $input)) {
            unset($customer['contact_id']);
        }

        if (! array_key_exists('company_id', $input)) {
            unset($customer['company_id']);
        }

        $this->tickets->update($ticket, [...$input, ...$customer], $this->support->membership($actor));
        Toast::success(__('Ticket updated.'));

        return back();
    }

    /** Private files: an attachment with `nosniff`, only to people who may see the ticket. */
    public function download(Request $request, Ticket $ticket, Attachment $attachment): HttpResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($attachment->ticket_id === $ticket->id, 404);
        $this->access->authorize($actor, SupportPermissions::TICKETS_VIEW, $ticket);

        $contents = $this->attachments->contents($attachment);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes(preg_replace('/[\r\n]+/', ' ', $attachment->original_name) ?? 'file', '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** A canned response with its placeholders filled for this ticket. Plain text only. */
    public function cannedPreview(Request $request, Ticket $ticket, CannedResponse $canned): JsonResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_REPLY, $ticket);
        abort_unless($canned->is_active && $actor->can(SupportPermissions::CANNED_VIEW), 404);
        $ticket->loadMissing(['contact:id,first_name,last_name,email', 'company:id,name']);

        return response()->json([
            'id' => $canned->id,
            'body' => PlaceholderRenderer::render($canned->body, [
                'requester.name' => $ticket->displayRequester(),
                'requester.email' => $ticket->requester_email ?? $ticket->contact?->email,
                'ticket.number' => $ticket->number,
                'ticket.subject' => $ticket->subject,
                'agent.name' => $actor->name,
                'workspace.name' => $this->settings->workspaceName(),
            ]),
        ]);
    }

    /**
     * Names for the ids that assignment and queue events carry, in two tenant-scoped queries.
     *
     * @param  Collection<int, TicketEvent>  $events
     * @return array{members: array<string, string>, queues: array<string, string>}
     */
    private function resolveEventNames(Collection $events): array
    {
        $members = [];
        $queues = [];

        foreach ($events as $event) {
            $ids = array_filter([$event->data['from'] ?? null, $event->data['to'] ?? null], 'is_string');

            match ($event->kind) {
                TicketEventKind::Assigned, TicketEventKind::Unassigned => array_push($members, ...$ids),
                TicketEventKind::QueueChanged => array_push($queues, ...$ids),
                default => null,
            };
        }

        return [
            'members' => $members === [] ? [] : TenantMembership::query()->whereKey(array_unique($members))->with('user:id,name')->get()
                ->mapWithKeys(fn (TenantMembership $member): array => [$member->id => $member->user->name])->all(),
            'queues' => $queues === [] ? [] : Queue::query()->whereKey(array_unique($queues))->pluck('name', 'id')->all(),
        ];
    }

    /**
     * @param  array{members: array<string, string>, queues: array<string, string>}  $names
     * @return array<string, string|null>
     */
    private function eventNames(TicketEvent $event, array $names): array
    {
        $source = match ($event->kind) {
            TicketEventKind::Assigned, TicketEventKind::Unassigned => $names['members'],
            TicketEventKind::QueueChanged => $names['queues'],
            default => null,
        };

        if ($source === null) {
            return [];
        }

        $from = $event->data['from'] ?? null;
        $to = $event->data['to'] ?? null;

        return [
            'fromName' => is_string($from) ? ($source[$from] ?? null) : null,
            'toName' => is_string($to) ? ($source[$to] ?? null) : null,
        ];
    }

    /** @return list<string> */
    private function statusTargets(TicketStatus $from): array
    {
        return array_map(static fn (TicketStatus $status): string => $status->value, match ($from) {
            TicketStatus::New, TicketStatus::Open, TicketStatus::WaitingCustomer, TicketStatus::OnHold => [
                TicketStatus::Open, TicketStatus::WaitingCustomer, TicketStatus::OnHold, TicketStatus::Resolved, TicketStatus::Closed,
            ],
            TicketStatus::Resolved => [TicketStatus::Open, TicketStatus::Closed],
            TicketStatus::Closed => [TicketStatus::Open],
            TicketStatus::Merged => [],
        });
    }

    /** @return array{id: string, number: string}|null */
    private function reference(User $actor, string $ticketId): ?array
    {
        $ticket = $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_VIEW)->find($ticketId, ['id', 'number']);

        return $ticket === null ? null : ['id' => $ticket->id, 'number' => $ticket->number];
    }
}
