<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;

/** Daily housekeeping: closes long-resolved tickets (when configured) and expires unanswered surveys. */
final class SupportMaintenance
{
    public function __construct(
        private readonly SupportSettings $settings,
        private readonly TicketManager $tickets,
        private readonly SurveyManager $surveys,
    ) {}

    /** @return array{closed: int, expired_surveys: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $closed = 0;
        $days = $this->settings->current()->auto_close_days;

        if ($days !== null && $days > 0) {
            Ticket::query()
                ->where('status', TicketStatus::Resolved)
                ->where('resolved_at', '<=', $now->subDays($days))
                ->orderBy('id')
                ->chunkById(100, function ($tickets) use (&$closed): void {
                    foreach ($tickets as $ticket) {
                        $this->tickets->changeStatus($ticket, TicketStatus::Closed, null);
                        $closed++;
                    }
                });
        }

        return ['closed' => $closed, 'expired_surveys' => $this->surveys->expireStale()];
    }
}
