<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** An agent sent a public reply. */
final class TicketReplied extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.replied';
    }
}
