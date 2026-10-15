<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

use App\Core\Identity\Models\User;

/** What the member asked the inbox for. Every value was validated by the inbox before it reaches a source. */
final readonly class InboxQuery
{
    public const int MAX_LIMIT = 50;

    /**
     * @param  string|null  $membershipId  The member's own membership, for `owner: me`.
     * @param  list<InboxState>  $states  Only conversations in one of these states.
     * @param  'me'|'unassigned'|null  $owner
     * @param  string  $search  Trimmed, at most 100 characters; empty means no search.
     * @param  bool  $unread  Only conversations whose item would carry `unread > 0` (the source's own unread notion:
     *                        unread messages, or a reply awaited). A source without one returns nothing. Applies to
     *                        `items()` and `counts()` alike.
     */
    public function __construct(
        public User $user,
        public ?string $membershipId,
        public array $states,
        public ?string $owner = null,
        public string $search = '',
        public int $limit = 25,
        public bool $unread = false,
    ) {}

    /** @return list<string> */
    public function stateValues(): array
    {
        return array_map(static fn (InboxState $state): string => $state->value, $this->states);
    }
}
