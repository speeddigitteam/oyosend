<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** Messages were split from a ticket into a new ticket. */
final class TicketSplit extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.split';
    }
}
