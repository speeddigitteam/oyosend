<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A ticket was closed. */
final class TicketClosed extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.closed';
    }
}
