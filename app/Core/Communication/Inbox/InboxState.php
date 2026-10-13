<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

/** Where a conversation stands in the unified inbox. Each source maps its own statuses onto these three. */
enum InboxState: string
{
    /** Open and nobody owns it. */
    case Unassigned = 'unassigned';

    /** Open and someone owns it. */
    case Active = 'active';

    /** Finished: closed, resolved or merged. */
    case Closed = 'closed';
}
