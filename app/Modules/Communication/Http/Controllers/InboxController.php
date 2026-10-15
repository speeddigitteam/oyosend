<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Communication\Contracts\InboxReplies;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Communication\Inbox\InboxThread;
use App\Core\Identity\Models\User;
use App\Core\Realtime\Realtime;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Actions\InboxReplySuggestions;
use App\Modules\Communication\Actions\UnifiedInbox;
use App\Modules\Communication\Realtime\InboxChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The unified inbox page: WhatsApp conversations, Facebook comments, support tickets and later channels, newest
 * first (ADR 0020). A conversation of a source that offers chat opens beside the list (`?open={source}:{id}`) and is
 * answered from here (ADR 0030); the reply goes through the owning app's own action. Loading the page changes
 * nothing; marking a conversation read is its own POST.
 */
final class InboxController
{
    public const string VIEW = 'communication.inbox.view';

    public const int PAGE = 25;

    /** `{module}.{source}:{ulid}`: which conversation is open beside the list. */
    public const string OPEN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*:[0-9A-Za-z]{26}$/';

    /** Filter value => states it shows. */
    private const array STATES = [
        'open' => [InboxState::Unassigned, InboxState::Active],
        'unassigned' => [InboxState::Unassigned],
        'active' => [InboxState::Active],
        'closed' => [InboxState::Closed],
        'all' => [InboxState::Unassigned, InboxState::Active, InboxState::Closed],
    ];

    public function __construct(
        private readonly UnifiedInbox $inbox,
        private readonly AccessChecker $access,
        private readonly InboxReplySuggestions $suggestions,
        private readonly Realtime $realtime,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $available = $this->inbox->availableFor($actor);
        $input = $request->validate([
            ...$this->listRules($available),
            'view' => ['nullable', Rule::in(['list', 'board'])],
            'after' => ['nullable', 'string', 'max:2000'],
            'open' => ['nullable', 'string', 'max:120', 'regex:'.self::OPEN],
            'messages' => ['nullable', 'integer', 'min:1', 'max:'.InboxReplies::MAX_LIMIT],
        ]);
        $view = $input['view'] ?? 'list';
        $state = $input['state'] ?? 'open';
        $open = $input['open'] ?? null;
        $sources = isset($input['source']) ? [$input['source'] => $available[$input['source']]] : $available;
        $query = fn (array $states, int $limit): InboxQuery => $this->query($actor, $input, $states, $limit);
        $counted = null;
        // Closures: a partial reload (the next page, or the open thread polling for news) computes only its own prop.
        $counts = function () use (&$counted, $sources, $query): array {
            return $counted ??= $this->inbox->counts($sources, $query(InboxState::cases(), 1));
        };

        return Inertia::render('Modules/Communication/Pages/Inbox/Index', [
            'sources' => array_values(array_map(static fn (InboxSource $source): array => [
                'key' => $source->definition()->key,
                'label' => __($source->definition()->label),
                'channel' => $source->definition()->channel,
            ], $available)),
            // ADR 0035: how the page hears "a conversation changed" at once; null while realtime is off (it polls).
            'realtime' => $this->realtimeClient(),
            'filters' => ['view' => $view, 'source' => $input['source'] ?? null, 'owner' => $input['owner'] ?? null, 'state' => $state, 'search' => trim((string) ($input['search'] ?? '')), 'unread' => (bool) ($input['unread'] ?? false), 'open' => $open],
            // Only beside the list. Null when the conversation cannot be shown, the same whether it is missing, foreign
            // or out of scope: the page then says it is no longer available instead of an error page (a poll can meet
            // a conversation the member just lost). Read-only: the page marks it read with a POST (`read` below).
            'thread' => $open === null || $view === 'board' ? null : fn (): ?array => $this->thread($actor, $open, (int) ($input['messages'] ?? InboxReplies::DEFAULT_LIMIT), $available),
            'counts' => $counts,
            'list' => $view === 'board' ? null : function () use ($sources, $query, $state, $input, $available): array {
                $page = $this->inbox->page($sources, $query(self::STATES[$state], self::PAGE), $input['after'] ?? null);

                return ['items' => $this->present($page['items'], $available), 'next' => $page['next'], 'after' => $input['after'] ?? null];
            },
            'columns' => $view !== 'board' ? null : fn (): array => array_map(fn (InboxState $column): array => [
                'state' => $column->value,
                'total' => $counts()[$column->value],
                'items' => $this->present($this->inbox->page($sources, $query([$column], self::PAGE))['items'], $available),
            ], InboxState::cases()),
        ]);
    }

    /**
     * The newest page of the list for the same filters as the page, as JSON: the list view asks for it every 20 seconds
     * while the tab is visible and merges it into the rows it already shows, so the pages loaded with "Load more" stay
     * (ADR 0030). Read-only, like every GET of the inbox: it never marks anything read. No position (`after`) is taken,
     * because only the newest page can bring news.
     */
    public function poll(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $available = $this->inbox->availableFor($actor);
        $input = $request->validate($this->listRules($available));
        $sources = isset($input['source']) ? [$input['source'] => $available[$input['source']]] : $available;
        $page = $this->inbox->page($sources, $this->query($actor, $input, self::STATES[$input['state'] ?? 'open'], self::PAGE));

        return response()->json(['items' => $this->present($page['items'], $available), 'next' => $page['next']]);
    }

    /** A free-form reply written in the inbox, handed to the owning app's own action (ADR 0030). */
    public function reply(Request $request, string $source, string $id): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $input = $request->validate(['text' => ['required', 'string', 'max:'.InboxReplies::MAX_TEXT]]);

        $message = $this->inbox->reply($source, $id, (string) $input['text'], $actor);
        Toast::success($message->status === 'queued' ? __('Reply queued.') : __('Reply sent.'));

        return back();
    }

    /**
     * A reply suggestion for the open conversation's composer, from the AI text assistant (ADR 0030, ADR 0024). The
     * same gates as a reply (404 for a conversation the member may not open or answer, 403 without the source's reply
     * permission, 422 on `text` when the channel refuses a reply now). It sends, saves and marks nothing: the text goes
     * into the composer for the member to check, edit and send. A refusal of the assistant is an honest state with a
     * message safe to show (`not_configured`, `disabled`, `limit_reached`, `refused`, `failed`).
     */
    public function suggest(Request $request, string $source, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        return response()->json($this->suggestions->suggest($source, $id, $actor));
    }

    /**
     * The member read the open conversation (ADR 0030). A POST, so a crafted link cannot mark anything read: the page
     * sends it in the background (204), a plain form gets a redirect back. A source without an unread state is a
     * no-op; a conversation the member may not open is a 404.
     */
    public function read(Request $request, string $source, string $id): HttpResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->inbox->markRead($source, $id, $actor);

        return $request->expectsJson() ? response()->noContent() : back();
    }

    /**
     * The websocket address, the public key and the workspace's inbox channel, or null while realtime is off. Never
     * the secret.
     *
     * @return array{key: string, host: string, port: int, scheme: 'http'|'https', channel: string, authEndpoint: string}|null
     */
    private function realtimeClient(): ?array
    {
        $client = $this->realtime->client();

        if ($client === null) {
            return null;
        }

        return [...$client, 'channel' => InboxChannel::name($this->context->requireId()), 'authEndpoint' => route('core.realtime.auth', [], false)];
    }

    /**
     * The list filters shared by the page and its refresh.
     *
     * @param  array<string, InboxSource>  $available
     * @return array<string, list<mixed>>
     */
    private function listRules(array $available): array
    {
        return [
            'source' => ['nullable', 'string', Rule::in(array_keys($available))],
            'owner' => ['nullable', Rule::in(['me', 'unassigned'])],
            'state' => ['nullable', Rule::in(array_keys(self::STATES))],
            'search' => ['nullable', 'string', 'max:100'],
            // Only conversations with something unread (each source's own notion); it narrows the counts too.
            'unread' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<InboxState>  $states
     */
    private function query(User $actor, array $input, array $states, int $limit): InboxQuery
    {
        return new InboxQuery(
            user: $actor,
            membershipId: $this->access->membership($actor)?->id,
            states: $states,
            owner: $input['owner'] ?? null,
            search: trim((string) ($input['search'] ?? '')),
            limit: $limit,
            unread: (bool) ($input['unread'] ?? false),
        );
    }

    /**
     * @param  array<string, InboxSource>  $available
     * @return array<string, mixed>|null
     */
    private function thread(User $actor, string $open, int $limit, array $available): ?array
    {
        [$sourceKey, $id] = explode(':', $open, 2);
        $thread = $this->inbox->open($sourceKey, $id, $actor, $limit);

        if ($thread === null) {
            return null;
        }

        return $this->presentThread($sourceKey, $thread, $limit, $this->inbox->mayReply($sourceKey, $actor), __($available[$sourceKey]->definition()->label));
    }

    /** @return array<string, mixed> */
    private function presentThread(string $sourceKey, InboxThread $thread, int $limit, bool $mayReply, string $label): array
    {
        return [
            'key' => $sourceKey.':'.$thread->id,
            'source' => $sourceKey,
            'id' => $thread->id,
            'sourceLabel' => $label,
            'channel' => $thread->channel,
            // The source has an unread state: the page posts `communication.inbox.read` while the member looks at it.
            'marksRead' => $this->inbox->marksRead($sourceKey),
            'title' => $thread->title,
            'subtitle' => $thread->subtitle,
            'contactId' => $thread->contactId,
            'url' => $thread->url,
            // `replyAllowed`: the member holds the reply permission at all (otherwise no composer is shown).
            'replyAllowed' => $mayReply,
            'canReply' => $mayReply && $thread->canReply,
            // The composer offers "Suggest a reply": the member can answer now and the workspace has the AI assistant.
            'aiDrafting' => $mayReply && $thread->canReply && $this->suggestions->available(),
            'replyBlockedReason' => $mayReply ? $thread->replyBlockedReason : null,
            'hasOlder' => $thread->hasOlder,
            'limit' => $limit,
            'messages' => array_map(static fn (InboxMessage $message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'text' => $message->text,
                'at' => $message->at->toIso8601String(),
                'author' => $message->authorName,
                'status' => $message->status,
                'attachment' => $message->attachmentLabel,
                'attachmentKind' => $message->attachmentKind,
            ], $thread->messages),
        ];
    }

    /**
     * @param  list<array{source: string, item: InboxItem}>  $entries
     * @param  array<string, InboxSource>  $sources
     * @return list<array<string, mixed>>
     */
    private function present(array $entries, array $sources): array
    {
        return array_map(function (array $entry) use ($sources): array {
            $item = $entry['item'];
            $source = $sources[$entry['source']];
            $definition = $source->definition();

            return [
                'key' => $entry['source'].':'.$item->id,
                'source' => $entry['source'],
                'id' => $item->id,
                'sourceLabel' => __($definition->label),
                'channel' => $definition->channel,
                'title' => $item->title,
                'preview' => $item->preview,
                'at' => $item->at->toIso8601String(),
                'state' => $item->state->value,
                'owner' => $item->ownerName,
                'unread' => $item->unread,
                'url' => $item->url,
                'contactId' => $item->contactId,
                // Opens beside the list and can be answered here; otherwise the item opens its own app.
                'chat' => $this->inbox->chats($source),
            ];
        }, $entries);
    }
}
