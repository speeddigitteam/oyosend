<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketSlaBreached;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Detects missed SLA targets for the active workspace. Safe to run any number of times: each target of a
 * ticket is stamped (`*_breached_at`) and announced exactly once, under the ticket's row lock. Tickets whose
 * clocks are paused are skipped, and so are resolved, closed and merged tickets.
 */
final class SlaMonitor
{
    public function __construct(
        private readonly TicketManager $tickets,
        private readonly TicketHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int The number of targets newly marked as breached. */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        Ticket::query()
            ->whereIn('status', TicketStatus::activeValues())
            ->whereNull('sla_paused_at')
            ->where(function (Builder $due) use ($now): void {
                $due->where(function (Builder $first) use ($now): void {
                    $first->whereNull('first_responded_at')
                        ->whereNull('first_response_breached_at')
                        ->where('first_response_due_at', '<=', $now);
                })->orWhere(function (Builder $resolution) use ($now): void {
                    $resolution->whereNull('resolved_at')
                        ->whereNull('resolution_breached_at')
                        ->where('resolution_due_at', '<=', $now);
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($tickets) use ($now, &$count): void {
                foreach ($tickets as $ticket) {
                    $count += $this->breach($ticket->id, $now);
                }
            });

        return $count;
    }

    private function breach(string $ticketId, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($ticketId, $now): int {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();

            if ($ticket === null || ! $ticket->status->isActive() || $ticket->sla_paused_at !== null) {
                return 0;
            }

            $breached = [];

            if ($ticket->first_responded_at === null
                && $ticket->first_response_breached_at === null
                && $ticket->first_response_due_at !== null
                && $ticket->first_response_due_at->lessThanOrEqualTo($now)) {
                $ticket->first_response_breached_at = $now;
                $breached['first_response'] = $ticket->first_response_due_at;
            }

            if ($ticket->resolved_at === null
                && $ticket->resolution_breached_at === null
                && $ticket->resolution_due_at !== null
                && $ticket->resolution_due_at->lessThanOrEqualTo($now)) {
                $ticket->resolution_breached_at = $now;
                $breached['resolution'] = $ticket->resolution_due_at;
            }

            if ($breached === []) {
                return 0;
            }

            $ticket->save();

            foreach ($breached as $target => $dueAt) {
                $this->history->record($ticket, TicketEventKind::SlaBreached, null, ['target' => $target, 'due_at' => $dueAt->toIso8601String()]);
                $this->audit->record('support.ticket.sla_breached', $ticket, ['number' => $ticket->number, 'target' => $target]);
                TicketSlaBreached::dispatch($this->tickets->data($ticket) + ['target' => $target, 'due_at' => $dueAt->toIso8601String()]);
            }

            return count($breached);
        });
    }
}
