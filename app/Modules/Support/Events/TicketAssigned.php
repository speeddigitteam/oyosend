<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A ticket was assigned to an agent, or its assignee changed. */
final class TicketAssigned extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.assigned';
    }
}
