<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Identity\Models\User;

/**
 * An inbox source whose conversations carry an unread state that the unified inbox clears when a member reads the
 * thread there (ADR 0030). Optional, beside {@see InboxReplies}: a source without an unread state (Facebook comments,
 * whose state is "awaiting an answer") does not implement it.
 *
 * The inbox calls it only after {@see InboxReplies::thread()} returned the thread for the same viewer, so the source
 * may assume the viewer can see it; it still must not change a conversation the viewer may not see.
 */
interface MarksInboxRead
{
    /**
     * Mark the conversation read, as its own conversation page does when opened. A no-op when nothing is unread.
     */
    public function markRead(string $id, User $viewer): void;
}
