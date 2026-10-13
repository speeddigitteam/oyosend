<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Support\PhoneNumber;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\ChannelStatus;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\NoteManager;
use App\Modules\WhatsappMarketing\Actions\WhatsappNumbers;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ConversationNote;
use App\Modules\WhatsappMarketing\Models\Feedback;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Permissions;
use App\Modules\WhatsappMarketing\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The shared inbox: the conversation list, one thread, starting a conversation with a template. */
final class InboxController
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationManager $conversations,
        private readonly Messenger $messenger,
        private readonly ChannelStatus $channel,
        private readonly RecordAccess $records,
        private readonly Presenter $presenter,
        private readonly WhatsappNumbers $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->presenter->actor($request);
        $membership = $this->presenter->membership($actor);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed', 'all'])],
            'assignee' => ['nullable', 'string', 'max:26'],
            'tag' => ['nullable', 'string', 'max:30'],
            'unread' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'contact_search' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', Rule::in(['list', 'kanban'])],
        ]);
        $view = $filters['view'] ?? 'list';
        $status = $filters['status'] ?? 'open';
        $search = trim((string) ($filters['search'] ?? ''));
        $assignee = $filters['assignee'] ?? null;

        $base = fn () => $this->access->constrain(Conversation::query(), $actor, Permissions::CONVERSATIONS_VIEW);

        $open = fn () => $base()->where('status', ConversationStatus::Open->value);
        $contactSearch = trim((string) ($filters['contact_search'] ?? ''));
        $canStart = $actor->can(Permissions::CONVERSATIONS_REPLY);

        $shared = [
            'filters' => [
                'view' => $view,
                'status' => $status,
                'assignee' => $assignee,
                'tag' => $filters['tag'] ?? null,
                'unread' => ($filters['unread'] ?? false) === true,
                'search' => $search,
            ],
            'counts' => [
                'open' => $open()->count(),
                'mine' => $open()->where('owner_id', $membership->id)->count(),
                'unassigned' => $open()->whereNull('owner_id')->count(),
                'unread' => $open()->where('unread_count', '>', 0)->count(),
            ],
            'agents' => $this->presenter->agents(),
            'channel' => $this->channel->describe(),
            'numbers' => $this->numbers->picker(),
            'canStart' => $canStart,
            'contactMatches' => $canStart && $contactSearch !== ''
                ? $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                    ->whereNotNull('phone')
                    ->where(function ($match) use ($contactSearch): void {
                        $like = Like::contains($contactSearch);
                        $match->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('phone', 'like', $like);
                    })
                    ->orderBy('first_name')->limit(10)->get(['id', 'first_name', 'last_name', 'phone'])
                    ->map(fn (Contact $contact): array => [...$this->presenter->contact($contact), 'validPhone' => Phone::e164($contact->phone) !== null])
                    ->all()
                : [],
            'templates' => $canStart ? $this->approvedTemplates() : [],
        ];

        if ($view === 'kanban') {
            $applySearch = function ($query) use ($search, $filters): void {
                if ($search !== '') {
                    $like = Like::contains($search);
                    $query->where(function ($match) use ($like): void {
                        $match->where('phone', 'like', $like)
                            ->orWhere('profile_name', 'like', $like)
                            ->orWhereIn('contact_id', Contact::query()
                                ->where(fn ($contact) => $contact->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like))
                                ->select('id'));
                    });
                }
                if (isset($filters['tag'])) {
                    $query->whereJsonContains('tags', $filters['tag']);
                }
            };

            $kanbanBase = fn () => $base()
                ->with(Presenter::CONVERSATION_RELATIONS)
                ->tap($applySearch)
                ->orderByDesc('last_message_at')
                ->orderByDesc('id');

            return Inertia::render('Modules/WhatsappMarketing/Pages/Inbox/Index', [
                ...$shared,
                'conversations' => null,
                'kanbanColumns' => [
                    [
                        'key' => 'unassigned',
                        'count' => $base()->tap($applySearch)->where('status', 'open')->whereNull('owner_id')->count(),
                        'items' => $kanbanBase()->where('status', 'open')->whereNull('owner_id')->limit(50)->get()
                            ->map(fn (Conversation $conversation): array => $this->presenter->conversation($conversation))->all(),
                    ],
                    [
                        'key' => 'active',
                        'count' => $base()->tap($applySearch)->where('status', 'open')->whereNotNull('owner_id')->count(),
                        'items' => $kanbanBase()->where('status', 'open')->whereNotNull('owner_id')->limit(50)->get()
                            ->map(fn (Conversation $conversation): array => $this->presenter->conversation($conversation))->all(),
                    ],
                    [
                        'key' => 'closed',
                        'count' => $base()->tap($applySearch)->where('status', 'closed')->count(),
                        'items' => $kanbanBase()->where('status', 'closed')->limit(25)->get()
                            ->map(fn (Conversation $conversation): array => $this->presenter->conversation($conversation))->all(),
                    ],
                ],
            ]);
        }

        $list = $base()
            ->with(Presenter::CONVERSATION_RELATIONS)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($assignee === 'me', fn ($query) => $query->where('owner_id', $membership->id))
            ->when($assignee === 'unassigned', fn ($query) => $query->whereNull('owner_id'))
            ->when($assignee !== null && ! in_array($assignee, ['me', 'unassigned'], true), fn ($query) => $query->where('owner_id', $assignee))
            ->when(isset($filters['tag']), fn ($query) => $query->whereJsonContains('tags', $filters['tag']))
            ->when(($filters['unread'] ?? false) === true, fn ($query) => $query->where('unread_count', '>', 0))
            ->when($search !== '', function ($query) use ($search): void {
                $like = Like::contains($search);
                $query->where(function ($match) use ($like): void {
                    $match->where('phone', 'like', $like)
                        ->orWhere('profile_name', 'like', $like)
                        ->orWhereIn('contact_id', Contact::query()
                            ->where(fn ($contact) => $contact->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like))
                            ->select('id'));
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Conversation $conversation): array => $this->presenter->conversation($conversation));

        return Inertia::render('Modules/WhatsappMarketing/Pages/Inbox/Index', [
            ...$shared,
            'conversations' => $list,
            'kanbanColumns' => [],
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_VIEW, $conversation);
        $conversation->load([...Presenter::CONVERSATION_RELATIONS, 'department:id,name', 'branch:id,name']);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('author.user:id,name')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(200)->get()
            ->reverse()->values();

        $can = fn (string $permission): bool => $this->access->allows($actor, $permission, $conversation);
        $phoneContacts = [];

        if ($conversation->contact_id === null && $can(Permissions::CONVERSATIONS_MANAGE)) {
            $digits = PhoneNumber::digitVariants($conversation->phone);
            $strip = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
            $phoneContacts = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                ->whereNotNull('phone')->whereRaw($strip.' in ('.implode(',', array_fill(0, count($digits), '?')).')', $digits)
                ->limit(5)->get(['id', 'first_name', 'last_name', 'phone'])
                ->map(fn (Contact $contact): array => $this->presenter->contact($contact))->all();
        }

        $replyable = $can(Permissions::CONVERSATIONS_REPLY);
        $manageable = $can(Permissions::CONVERSATIONS_MANAGE);
        $membership = $this->presenter->membership($actor);
        $notes = ConversationNote::query()
            ->where('conversation_id', $conversation->id)
            ->with('author.user:id,name')
            ->orderBy('created_at')->orderBy('id')
            ->limit(NoteManager::MAX_NOTES)->get();
        $feedback = Feedback::query()->where('conversation_id', $conversation->id)->latest('requested_at')->first();

        return Inertia::render('Modules/WhatsappMarketing/Pages/Inbox/Show', [
            'conversation' => [
                ...$this->presenter->conversation($conversation),
                'createdAt' => $conversation->created_at->toIso8601String(),
                'firstRespondedAt' => $conversation->first_responded_at?->toIso8601String(),
                'closedAt' => $conversation->closed_at?->toIso8601String(),
                'department' => $conversation->department === null ? null : ['id' => $conversation->department->id, 'name' => $conversation->department->name],
                'branch' => $conversation->branch === null ? null : ['id' => $conversation->branch->id, 'name' => $conversation->branch->name],
                'contactPhoneMatches' => $conversation->contact !== null && Phone::e164($conversation->contact->phone) === $conversation->phone,
            ],
            'messages' => $messages->map(fn (Message $message): array => $this->presenter->message($message))->all(),
            'notes' => $notes->map(fn (ConversationNote $note): array => [
                ...$this->presenter->note($note),
                'removable' => $manageable || ($note->author_id !== null && $note->author_id === $membership->id),
            ])->all(),
            'feedback' => $feedback === null ? null : [
                'status' => $feedback->status->value,
                'rating' => $feedback->rating,
                'requestedAt' => $feedback->requested_at->toIso8601String(),
                'answeredAt' => $feedback->answered_at?->toIso8601String(),
            ],
            'templates' => $replyable ? $this->approvedTemplates() : [],
            'quickReplies' => $replyable
                ? QuickReply::query()->where('is_active', true)->orderBy('shortcut')->limit(100)->get()
                    ->map(fn (QuickReply $reply): array => $this->presenter->quickReply($reply))->all()
                : [],
            'agents' => $this->presenter->agents(),
            'phoneContacts' => $phoneContacts,
            'channel' => $this->channel->describe(),
            'numbers' => $this->numbers->picker(),
            'can' => [
                'reply' => $replyable,
                'assign' => $can(Permissions::CONVERSATIONS_ASSIGN),
                'assignOthers' => $this->access->hasAll($actor, Permissions::CONVERSATIONS_ASSIGN),
                'manage' => $manageable,
                'isAgent' => $this->access->isAgent($actor),
                'createContact' => $actor->can('core.contacts.manage'),
            ],
        ]);
    }

    /** Start a conversation with a contact by sending an approved template. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $membership = $this->presenter->membership($actor);
        $input = $request->validate([
            'contact_id' => ['required', 'string', 'size:26'],
            'template_id' => ['required', 'string', 'size:26'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'sender_number_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-Za-z]{26}$/'],
        ]);

        $contact = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->find($input['contact_id']);
        abort_if($contact === null, 404);

        $template = Template::query()->find($input['template_id']);
        abort_if($template === null, 404);

        // One unit: a refused send leaves no empty conversation behind.
        $conversation = DB::transaction(function () use ($contact, $actor, $template, $input, $membership): Conversation {
            $conversation = $this->conversations->forContact($contact)
                ?? throw ValidationException::withMessages(['contact_id' => __('This contact has no phone number with a country code.')]);
            $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
            $this->messenger->sendTemplate($conversation, $template, array_values(array_map('strval', $input['variables'] ?? [])), $membership, senderNumberId: $input['sender_number_id'] ?? null);

            return $conversation;
        });
        Toast::success(__('Template queued.'));

        return redirect()->route('whatsapp_marketing.inbox.show', $conversation);
    }

    public function read(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_VIEW, $conversation);
        $this->conversations->markRead($conversation);

        return back();
    }

    /** @return list<array<string, mixed>> */
    private function approvedTemplates(): array
    {
        return Template::query()->where('status', TemplateStatus::Approved->value)->orderBy('name')->limit(200)->get()
            ->map(fn (Template $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'language' => $template->language,
                'category' => $template->category->value,
                'body' => $template->body_text,
                'variableCount' => $template->variable_count,
            ])->all();
    }
}
