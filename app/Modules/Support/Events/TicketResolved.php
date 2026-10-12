<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A ticket was marked resolved. */
final class TicketResolved extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.resolved';
    }
}
