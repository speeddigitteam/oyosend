<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A ticket was merged into another one. */
final class TicketMerged extends SupportEvent
{
    public static function name(): string
    {
        return 'support.ticket.merged';
    }
}
