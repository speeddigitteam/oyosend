<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Modules\Support\Events\TicketAssigned;

final class TicketAssignedTrigger extends SupportTrigger
{
    public static function key(): string
    {
        return 'support.ticket.assigned';
    }

    public static function event(): string
    {
        return TicketAssigned::class;
    }

    protected static function extraProperties(): array
    {
        return ['previous_owner_id' => ['type' => ['string', 'null']]];
    }
}
