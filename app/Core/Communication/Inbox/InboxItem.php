<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

use Carbon\CarbonImmutable;

/** One conversation, normalised for the unified inbox. Customer text in it is untrusted and rendered as text. */
final readonly class InboxItem
{
    /**
     * @param  string  $title  The contact's name, or the sender's address when no contact is linked.
     * @param  string|null  $preview  The last message or the subject, at most 140 characters.
     * @param  CarbonImmutable  $at  Last activity (UTC); orders the inbox and is the cursor.
     * @param  int  $unread  Unread messages, or 1 when the source only knows that a reply is awaited.
     * @param  string  $url  The owning app's own conversation page.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $preview,
        public CarbonImmutable $at,
        public InboxState $state,
        public string $url,
        public ?string $contactId = null,
        public ?string $ownerName = null,
        public int $unread = 0,
    ) {}
}
