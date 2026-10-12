<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;

/** Writes the append-only activity history of a ticket. */
final class TicketHistory
{
    /** @param array<string, scalar|null> $data */
    public function record(Ticket $ticket, TicketEventKind $kind, ?TenantMembership $actor = null, array $data = []): TicketEvent
    {
        return TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'kind' => $kind,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
