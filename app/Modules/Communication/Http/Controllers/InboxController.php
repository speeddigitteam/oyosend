<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Identity\Models\User;
use App\Modules\Communication\Actions\UnifiedInbox;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The unified inbox page: WhatsApp conversations, support tickets and later channels, newest first (ADR 0020). */
final class InboxController
{
    public const string VIEW = 'communication.inbox.view';

    public const int PAGE = 25;

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
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $available = $this->inbox->availableFor($actor);
        $input = $request->validate([
            'view' => ['nullable', Rule::in(['list', 'board'])],
            'source' => ['nullable', 'string', Rule::in(array_keys($available))],
            'owner' => ['nullable', Rule::in(['me', 'unassigned'])],
            'state' => ['nullable', Rule::in(array_keys(self::STATES))],
            'search' => ['nullable', 'string', 'max:100'],
            'after' => ['nullable', 'string', 'max:2000'],
        ]);
        $view = $input['view'] ?? 'list';
        $state = $input['state'] ?? 'open';
        $sources = isset($input['source']) ? [$input['source'] => $available[$input['source']]] : $available;
        $query = fn (array $states, int $limit): InboxQuery => new InboxQuery(
            user: $actor,
            membershipId: $this->access->membership($actor)?->id,
            states: $states,
            owner: $input['owner'] ?? null,
            search: trim((string) ($input['search'] ?? '')),
            limit: $limit,
        );
        $counts = $this->inbox->counts($sources, $query(InboxState::cases(), 1));
        $list = null;
        $columns = null;

        if ($view === 'board') {
            $columns = array_map(fn (InboxState $column): array => [
                'state' => $column->value,
                'total' => $counts[$column->value],
                'items' => $this->present($this->inbox->page($sources, $query([$column], self::PAGE))['items'], $available),
            ], InboxState::cases());
        } else {
            $page = $this->inbox->page($sources, $query(self::STATES[$state], self::PAGE), $input['after'] ?? null);
            $list = ['items' => $this->present($page['items'], $available), 'next' => $page['next'], 'after' => $input['after'] ?? null];
        }

        return Inertia::render('Modules/Communication/Pages/Inbox/Index', [
            'sources' => array_values(array_map(static fn (InboxSource $source): array => [
                'key' => $source->definition()->key,
                'label' => __($source->definition()->label),
                'channel' => $source->definition()->channel,
            ], $available)),
            'filters' => ['view' => $view, 'source' => $input['source'] ?? null, 'owner' => $input['owner'] ?? null, 'state' => $state, 'search' => trim((string) ($input['search'] ?? ''))],
            'counts' => $counts,
            'list' => $list,
            'columns' => $columns,
        ]);
    }

    /**
     * @param  list<array{source: string, item: InboxItem}>  $entries
     * @param  array<string, InboxSource>  $sources
     * @return list<array<string, mixed>>
     */
    private function present(array $entries, array $sources): array
    {
        return array_map(static function (array $entry) use ($sources): array {
            $item = $entry['item'];
            $definition = $sources[$entry['source']]->definition();

            return [
                'key' => $entry['source'].':'.$item->id,
                'source' => $entry['source'],
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
            ];
        }, $entries);
    }
}
