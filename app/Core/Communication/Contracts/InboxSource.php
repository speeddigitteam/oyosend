<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxSourceDefinition;
use App\Core\Communication\Inbox\InboxState;

/**
 * One kind of conversation shown in the unified inbox (ADR 0020, decision 4).
 *
 * The app that owns the conversations implements it and tags it in its own provider
 * (`$this->app->tag([MySource::class], InboxSource::TAG)`); the inbox imports nothing of that app. The inbox asks a
 * source only when the workspace is entitled to `definition()->module` and the member holds
 * `definition()->permission`.
 *
 * A source is READ-ONLY over its own records and narrows them exactly as its own inbox or list does. It applies the
 * query's states, owner, search and unread filter itself; "unread" means exactly what makes its items' `unread`
 * greater than zero, and a source without an unread notion returns nothing for it. Changes stay in the owning app, which the item's URL opens. A source may
 * also implement {@see InboxReplies} to let a member read and answer a conversation from the inbox (ADR 0030); the
 * reply still goes through the owning app's own action.
 */
interface InboxSource
{
    /** Container tag under which sources are registered. */
    public const string TAG = 'communication.inbox_sources';

    public function definition(): InboxSourceDefinition;

    /**
     * Newest first by (last activity, id), strictly older than `$after` when given; at most `$query->limit + 1`.
     *
     * @return list<InboxItem>
     */
    public function items(InboxQuery $query, ?InboxCursor $after = null): array;

    /**
     * How many conversations the member may see in each state, with the query's owner, search and unread filter
     * applied and its states ignored.
     *
     * @return array<value-of<InboxState>, int>
     */
    public function counts(InboxQuery $query): array;
}
