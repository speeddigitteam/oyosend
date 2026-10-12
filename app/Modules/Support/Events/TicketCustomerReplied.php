<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** The requester wrote on a ticket. */
final class TicketCustomerReplied extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.customer_replied';
    }
}
