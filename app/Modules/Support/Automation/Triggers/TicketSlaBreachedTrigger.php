<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Modules\Support\Events\TicketSlaBreached;

final class TicketSlaBreachedTrigger extends SupportTrigger
{
    public static function key(): string
    {
        return 'support.ticket.sla_breached';
    }

    public static function event(): string
    {
        return TicketSlaBreached::class;
    }

    protected static function extraProperties(): array
    {
        return [
            'target' => ['type' => 'string', 'enum' => ['first_response', 'resolution']],
            'due_at' => ['type' => 'string'],
        ];
    }
}
