<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A resolved or closed ticket was reopened. */
final class TicketReopened extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.reopened';
    }
}
