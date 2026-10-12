<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A ticket was opened (any channel). */
final class TicketCreated extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.created';
    }
}
