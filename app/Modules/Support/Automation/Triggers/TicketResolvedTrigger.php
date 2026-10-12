<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Modules\Support\Events\TicketResolved;

final class TicketResolvedTrigger extends SupportTrigger
{
    public static function key(): string
    {
        return 'support.ticket.resolved';
    }

    public static function event(): string
    {
        return TicketResolved::class;
    }
}
