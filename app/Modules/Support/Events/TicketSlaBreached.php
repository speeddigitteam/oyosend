<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A first-response or resolution target was missed (announced once per target). */
final class TicketSlaBreached extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.sla_breached';
    }
}
