<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Communication\Contracts\InboxReplies;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Contracts\MarksInboxRead;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Communication\Inbox\InboxThread;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The unified inbox (ADR 0020, decision 4): merges the conversations of every source the member may use, newest
 * first. It owns no conversation; each source reads and narrows its own.
 *
 * Paging keeps one position per source, so a later page continues every source exactly where it stopped.
 *
 * A source that implements {@see InboxReplies} can also be read and answered here (ADR 0030). The inbox sends
 * nothing itself: it checks the gates it owns (entitlement, the source's view permission, its reply permission) and
 * hands the reply to the source, which delegates to its own app's action.
 */
final class UnifiedInbox
{
    private const string AT_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, InboxSource>|null */
    private ?array $sources = null;

    public function __construct(
        private readonly Container $container,
        private readonly AccessChecker $access,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, InboxSource> */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];

        foreach ($this->container->tagged(InboxSource::TAG) as $source) {
            if (! $source instanceof InboxSource) {
                continue;
            }

            $key = $source->definition()->key;

            if (isset($sources[$key])) {
                throw new LogicException("Duplicate inbox source [{$key}].");
            }

            $sources[$key] = $source;
        }

        ksort($sources);

        return $this->sources = $sources;
    }

    /** @return array<string, InboxSource> */
    public function availableFor(User $user): array
    {
        $tenant = $this->context->require();

        return array_filter($this->all(), function (InboxSource $source) use ($user, $tenant): bool {
            $definition = $source->definition();

            return $this->entitlements->allows($tenant, $definition->module) && $this->access->allows($user, $definition->permission);
        });
    }

    /**
     * One page of the merged inbox and the position of the next one (null when there is none).
     *
     * @param  array<string, InboxSource>  $sources
     * @return array{items: list<array{source: string, item: InboxItem}>, next: string|null}
     *
     * @throws ValidationException when the position was not issued for these sources
     */
    public function page(array $sources, InboxQuery $query, ?string $after = null): array
    {
        $positions = $this->decode($after, array_keys($sources));
        $candidates = [];

        foreach ($sources as $key => $source) {
            foreach ($source->items($query, $positions[$key] ?? null) as $item) {
                $candidates[] = ['source' => $key, 'item' => $item];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['item']->at->getTimestamp(), $a['source'], $b['item']->id]
            <=> [$a['item']->at->getTimestamp(), $b['source'], $a['item']->id]);

        $page = array_slice($candidates, 0, $query->limit);

        foreach ($page as $entry) {
            $positions[$entry['source']] = new InboxCursor($entry['item']->at, $entry['item']->id);
        }

        return ['items' => $page, 'next' => count($candidates) > $query->limit ? $this->encode($positions) : null];
    }

    /**
     * @param  array<string, InboxSource>  $sources
     * @return array<value-of<InboxState>, int>
     */
    public function counts(array $sources, InboxQuery $query): array
    {
        $totals = array_fill_keys(array_map(static fn (InboxState $state): string => $state->value, InboxState::cases()), 0);

        foreach ($sources as $source) {
            foreach ($source->counts($query) as $state => $count) {
                $totals[$state] += $count;
            }
        }

        return $totals;
    }

    /** Whether a member may open the source's conversations here and answer them (ADR 0030). */
    public function chats(InboxSource $source): bool
    {
        return $source instanceof InboxReplies;
    }

    /**
     * One conversation with its newest messages, oldest first.
     *
     * @throws NotFoundHttpException when the source is unknown, not available to the member or offers no chat, and
     *                               when the conversation does not exist or the member may not see it
     */
    public function thread(string $sourceKey, string $id, User $viewer, int $limit = InboxReplies::DEFAULT_LIMIT): InboxThread
    {
        $limit = max(1, min($limit, InboxReplies::MAX_LIMIT));

        return $this->chatSource($sourceKey, $viewer)->thread($id, $viewer, $limit) ?? throw new NotFoundHttpException;
    }

    /**
     * The conversation for the reading pane, or null when it cannot be shown. Null is the same whether it does not
     * exist, belongs to another workspace, lies outside the member's scope or its source offers no chat, so nothing
     * is disclosed. Read-only: it never marks anything read (that is {@see markRead()}, on a POST).
     */
    public function open(string $sourceKey, string $id, User $viewer, int $limit = InboxReplies::DEFAULT_LIMIT): ?InboxThread
    {
        try {
            return $this->thread($sourceKey, $id, $viewer, $limit);
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    /** Whether the source's conversations carry an unread state that {@see markRead()} clears. */
    public function marksRead(string $sourceKey): bool
    {
        return ($this->all()[$sourceKey] ?? null) instanceof MarksInboxRead;
    }

    /**
     * The member read the conversation in the inbox: the source clears its unread state as its own conversation page
     * does. Only ever called from a POST, never while a page loads, so a link cannot mark anything read. A source
     * without an unread state ({@see MarksInboxRead} not implemented) is left untouched.
     *
     * @throws NotFoundHttpException as for {@see thread()}: an unknown or unavailable source, or a conversation the
     *                               member may not open (the source's own thread is the visibility guard)
     */
    public function markRead(string $sourceKey, string $id, User $viewer): void
    {
        $source = $this->chatSource($sourceKey, $viewer);

        if ($source->thread($id, $viewer, 1) === null) {
            throw new NotFoundHttpException;
        }

        if ($source instanceof MarksInboxRead) {
            $source->markRead($id, $viewer);
        }
    }

    /** Whether the member holds the permission the source's own reply route requires (false without chat). */
    public function mayReply(string $sourceKey, User $viewer): bool
    {
        $source = $this->availableFor($viewer)[$sourceKey] ?? null;

        return $source instanceof InboxReplies && $this->access->allows($viewer, $source->replyPermission());
    }

    /**
     * Reply to one conversation through the source, which hands it to its own app's action.
     *
     * @throws NotFoundHttpException as for {@see thread()}
     * @throws AccessDeniedHttpException when the member lacks the source's reply permission
     * @throws ValidationException on `text` when the owning app or the channel refuses the reply
     */
    public function reply(string $sourceKey, string $id, string $text, User $viewer): InboxMessage
    {
        $source = $this->chatSource($sourceKey, $viewer);

        if (! $this->access->allows($viewer, $source->replyPermission())) {
            throw new AccessDeniedHttpException;
        }

        // Only a conversation the member may open can be answered, whatever the source's reply checks next.
        if ($source->thread($id, $viewer, 1) === null) {
            throw new NotFoundHttpException;
        }

        return $source->reply($id, $text, $viewer);
    }

    /**
     * The conversation as the member could answer it now, with its newest `$limit` messages: the same gates as
     * {@see reply()} without writing anything. Used to draft a reply suggestion for the composer.
     *
     * @throws NotFoundHttpException as for {@see thread()}, and when the conversation lies outside the member's reply
     *                               scope (as the source's own reply answers)
     * @throws AccessDeniedHttpException when the member lacks the source's reply permission
     * @throws ValidationException on `text` when the channel would refuse a reply now, with the source's reason
     */
    public function answerable(string $sourceKey, string $id, User $viewer, int $limit): InboxThread
    {
        $source = $this->chatSource($sourceKey, $viewer);

        if (! $this->access->allows($viewer, $source->replyPermission())) {
            throw new AccessDeniedHttpException;
        }

        $thread = $source->thread($id, $viewer, max(1, min($limit, InboxReplies::MAX_LIMIT))) ?? throw new NotFoundHttpException;

        if (! $thread->canReply) {
            // No reason means the member may not answer this record at all: not disclosed, as a reply would be.
            if ($thread->replyBlockedReason === null) {
                throw new NotFoundHttpException;
            }

            throw ValidationException::withMessages(['text' => $thread->replyBlockedReason]);
        }

        return $thread;
    }

    /** The available source under the key, when it offers chat; otherwise not found (nothing is disclosed). */
    private function chatSource(string $sourceKey, User $viewer): InboxSource&InboxReplies
    {
        $source = $this->availableFor($viewer)[$sourceKey] ?? null;

        if (! $source instanceof InboxReplies) {
            throw new NotFoundHttpException;
        }

        return $source;
    }

    /** @param  array<string, InboxCursor>  $positions */
    private function encode(array $positions): string
    {
        $plain = array_map(static fn (InboxCursor $cursor): array => [$cursor->at->utc()->format(self::AT_FORMAT), $cursor->id], $positions);

        return rtrim(strtr(base64_encode((string) json_encode($plain, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, InboxCursor>
     */
    private function decode(?string $after, array $keys): array
    {
        if ($after === null || $after === '') {
            return [];
        }

        $invalid = ValidationException::withMessages(['after' => __('This page of the inbox is no longer valid. Start again from the first page.')]);

        try {
            $plain = json_decode((string) base64_decode(strtr($after, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $invalid;
        }

        if (! is_array($plain)) {
            throw $invalid;
        }

        $positions = [];

        foreach ($plain as $key => $value) {
            if (! in_array($key, $keys, true) || ! is_array($value) || count($value) !== 2 || ! is_string($value[0] ?? null) || ! is_string($value[1] ?? null)
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value[1]) !== 1) {
                throw $invalid;
            }

            $at = CarbonImmutable::createFromFormat(self::AT_FORMAT, $value[0], 'UTC');

            if (! $at instanceof CarbonImmutable || $at->format(self::AT_FORMAT) !== $value[0]) {
                throw $invalid;
            }

            $positions[$key] = new InboxCursor($at, $value[1]);
        }

        return $positions;
    }
}
