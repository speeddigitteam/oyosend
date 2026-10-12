<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Modules\Support\Events\TicketCustomerReplied;

final class TicketCustomerRepliedTrigger extends SupportTrigger
{
    public static function key(): string
    {
        return 'support.ticket.customer_replied';
    }

    public static function event(): string
    {
        return TicketCustomerReplied::class;
    }

    protected static function extraProperties(): array
    {
        return ['message_id' => ['type' => 'string']];
    }
}
