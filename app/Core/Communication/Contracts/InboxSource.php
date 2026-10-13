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
 * query's states, owner and search itself. Replies and changes stay in the owning app, which the item's URL opens.
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
     * How many conversations the member may see in each state, with the query's owner and search applied and its
     * states ignored.
     *
     * @return array<value-of<InboxState>, int>
     */
    public function counts(InboxQuery $query): array;
}
