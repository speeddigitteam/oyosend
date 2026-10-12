<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Modules\Support\Events\TicketCreated;

final class TicketCreatedTrigger extends SupportTrigger
{
    public static function key(): string
    {
        return 'support.ticket.created';
    }

    public static function event(): string
    {
        return TicketCreated::class;
    }
}
