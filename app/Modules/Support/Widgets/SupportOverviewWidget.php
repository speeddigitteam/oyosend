<?php

declare(strict_types=1);

namespace App\Modules\Support\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Illuminate\Database\Eloquent\Builder;

/** Dashboard counts of the tickets the person may see (`support.tickets.view`, with their scope). */
final class SupportOverviewWidget
{
    public function __construct(
        private readonly TicketAccess $access,
        private readonly AccessChecker $checker,
    ) {}

    /** @return array<string, int> */
    public function __invoke(User $user): array
    {
        $membershipId = $this->checker->membership($user)?->id;
        $active = fn (): Builder => $this->access
            ->constrain(Ticket::query(), $user, SupportPermissions::TICKETS_VIEW)
            ->whereIn('status', TicketStatus::activeValues());

        return [
            'open' => $active()->count(),
            'unassigned' => $active()->whereNull('owner_id')->count(),
            'mine' => $membershipId === null ? 0 : $active()->where('owner_id', $membershipId)->count(),
            'waitingOnCustomer' => $active()->where('status', TicketStatus::WaitingCustomer)->count(),
            'breached' => $active()->where(fn (Builder $match) => $match
                ->where(fn (Builder $first) => $first->whereNull('first_responded_at')->whereNotNull('first_response_breached_at'))
                ->orWhere(fn (Builder $resolution) => $resolution->whereNull('resolved_at')->whereNotNull('resolution_breached_at')))->count(),
        ];
    }
}
