<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Workspace-wide support reports. Dates are local dates in the workspace timezone; ratios are integer basis
 * points (10000 = 100%), average ratings are integer hundredths (450 = 4.50) and durations are whole minutes
 * of wall-clock time. Merged tickets are left out: their messages live on the surviving ticket.
 */
final class SupportReports
{
    public const int MAX_DAYS = 366;

    public function __construct(private readonly SupportSettings $settings) {}

    /**
     * @return array{
     *   range: array{from: string, to: string, days: int},
     *   volume: array{created: int, resolved: int, byDay: list<array{date: string, created: int, resolved: int}>},
     *   backlog: array{total: int, unassigned: int, byStatus: array<string, int>, byPriority: array<string, int>, byQueue: list<array{queueId: string|null, name: string|null, count: int}>, aging: array<string, int>},
     *   sla: array{firstResponse: array{met: int, missed: int, pending: int, rateBps: int|null}, resolution: array{met: int, missed: int, pending: int, rateBps: int|null}},
     *   times: array{firstResponseAverage: int|null, firstResponseMedian: int|null, resolutionAverage: int|null, resolutionMedian: int|null},
     *   csat: array{requested: int, responses: int, responseRateBps: int|null, averageHundredths: int|null, satisfiedBps: int|null, distribution: array<int, int>}
     * }
     */
    public function overview(string $from, string $to, ?string $queueId = null): array
    {
        $zone = $this->settings->timezone();
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $zone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $zone);
        } catch (Throwable) {
            $start = $end = null;
        }

        if ($start === null || $end === null || $end->lessThan($start)) {
            throw ValidationException::withMessages(['to' => __('Choose a valid date range.')]);
        }

        $days = (int) $start->diffInDays($end) + 1;

        if ($days > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Choose a range of at most :days days.', ['days' => self::MAX_DAYS])]);
        }

        $startUtc = $start->startOfDay()->utc();
        $endUtc = $end->endOfDay()->utc();

        return [
            'range' => ['from' => $from, 'to' => $to, 'days' => $days],
            'volume' => $this->volume($startUtc, $endUtc, $zone, $queueId),
            'backlog' => $this->backlog($queueId),
            'sla' => $this->sla($startUtc, $endUtc, $queueId),
            'times' => $this->times($startUtc, $endUtc, $queueId),
            'csat' => $this->csat($startUtc, $endUtc, $queueId),
        ];
    }

    /** @return array{created: int, resolved: int, byDay: list<array{date: string, created: int, resolved: int}>} */
    private function volume(CarbonImmutable $start, CarbonImmutable $end, string $zone, ?string $queueId): array
    {
        $days = [];

        for ($day = $start->setTimezone($zone)->startOfDay(); $day->lessThanOrEqualTo($end); $day = $day->addDay()->startOfDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'created' => 0, 'resolved' => 0];
        }

        $created = 0;
        $resolved = 0;

        foreach ($this->tickets($queueId)->whereBetween('created_at', [$start, $end])->cursor() as $ticket) {
            $key = $ticket->created_at->toImmutable()->setTimezone($zone)->toDateString();

            if (isset($days[$key])) {
                $days[$key]['created']++;
                $created++;
            }
        }

        foreach ($this->tickets($queueId)->whereNotNull('resolved_at')->whereBetween('resolved_at', [$start, $end])->cursor() as $ticket) {
            $key = $ticket->resolved_at?->toImmutable()->setTimezone($zone)->toDateString();

            if ($key !== null && isset($days[$key])) {
                $days[$key]['resolved']++;
                $resolved++;
            }
        }

        return ['created' => $created, 'resolved' => $resolved, 'byDay' => array_values($days)];
    }

    /** @return array{total: int, unassigned: int, byStatus: array<string, int>, byPriority: array<string, int>, byQueue: list<array{queueId: string|null, name: string|null, count: int}>, aging: array<string, int>} */
    private function backlog(?string $queueId): array
    {
        $now = CarbonImmutable::now();
        $byStatus = array_fill_keys(TicketStatus::activeValues(), 0);
        $byPriority = ['low' => 0, 'normal' => 0, 'high' => 0, 'urgent' => 0];
        $byQueue = [];
        $aging = ['under_1_day' => 0, '1_to_3_days' => 0, '3_to_7_days' => 0, 'over_7_days' => 0];
        $unassigned = 0;
        $total = 0;

        foreach ($this->tickets($queueId)->whereIn('status', TicketStatus::activeValues())->cursor() as $ticket) {
            $total++;
            $byStatus[$ticket->status->value]++;
            $byPriority[$ticket->priority->value]++;
            $byQueue[$ticket->queue_id ?? ''] = ($byQueue[$ticket->queue_id ?? ''] ?? 0) + 1;
            $unassigned += $ticket->owner_id === null ? 1 : 0;
            $hours = (int) $ticket->created_at->diffInHours($now);
            $aging[match (true) {
                $hours < 24 => 'under_1_day',
                $hours < 72 => '1_to_3_days',
                $hours < 168 => '3_to_7_days',
                default => 'over_7_days',
            }]++;
        }

        $names = Queue::query()->whereIn('id', array_filter(array_keys($byQueue)))->pluck('name', 'id');
        $rows = [];

        foreach ($byQueue as $id => $count) {
            $rows[] = ['queueId' => $id === '' ? null : $id, 'name' => $id === '' ? null : ($names[$id] ?? null), 'count' => $count];
        }

        return ['total' => $total, 'unassigned' => $unassigned, 'byStatus' => $byStatus, 'byPriority' => $byPriority, 'byQueue' => $rows, 'aging' => $aging];
    }

    /** @return array{firstResponse: array{met: int, missed: int, pending: int, rateBps: int|null}, resolution: array{met: int, missed: int, pending: int, rateBps: int|null}} */
    private function sla(CarbonImmutable $start, CarbonImmutable $end, ?string $queueId): array
    {
        $first = ['met' => 0, 'missed' => 0, 'pending' => 0];
        $resolution = ['met' => 0, 'missed' => 0, 'pending' => 0];

        foreach ($this->tickets($queueId)->whereBetween('created_at', [$start, $end])->whereNotNull('sla_policy_id')->cursor() as $ticket) {
            if ($ticket->first_response_due_at !== null || $ticket->first_responded_at !== null) {
                $first[$this->outcome($ticket->first_responded_at, $ticket->first_response_due_at, $ticket->first_response_breached_at, $ticket->status)]++;
            }

            if ($ticket->resolution_due_at !== null || $ticket->resolved_at !== null) {
                $resolution[$this->outcome($ticket->resolved_at, $ticket->resolution_due_at, $ticket->resolution_breached_at, $ticket->status)]++;
            }
        }

        return [
            'firstResponse' => $first + ['rateBps' => $this->rate($first['met'], $first['met'] + $first['missed'])],
            'resolution' => $resolution + ['rateBps' => $this->rate($resolution['met'], $resolution['met'] + $resolution['missed'])],
        ];
    }

    /** @return 'met'|'missed'|'pending' */
    private function outcome(?CarbonInterface $doneAt, ?CarbonInterface $dueAt, ?CarbonInterface $breachedAt, TicketStatus $status): string
    {
        if ($doneAt !== null) {
            return $dueAt !== null && $doneAt->greaterThan($dueAt) ? 'missed' : 'met';
        }

        if ($breachedAt !== null || ($dueAt !== null && $dueAt->isPast() && $status->isActive())) {
            return 'missed';
        }

        return 'pending';
    }

    /** @return array{firstResponseAverage: int|null, firstResponseMedian: int|null, resolutionAverage: int|null, resolutionMedian: int|null} */
    private function times(CarbonImmutable $start, CarbonImmutable $end, ?string $queueId): array
    {
        $first = [];
        $resolution = [];

        foreach ($this->tickets($queueId)->whereBetween('created_at', [$start, $end])->cursor() as $ticket) {
            if ($ticket->first_responded_at !== null) {
                $first[] = max(0, intdiv($ticket->first_responded_at->getTimestamp() - $ticket->created_at->getTimestamp(), 60));
            }

            if ($ticket->resolved_at !== null) {
                $resolution[] = max(0, intdiv($ticket->resolved_at->getTimestamp() - $ticket->created_at->getTimestamp(), 60));
            }
        }

        return [
            'firstResponseAverage' => $this->average($first),
            'firstResponseMedian' => $this->median($first),
            'resolutionAverage' => $this->average($resolution),
            'resolutionMedian' => $this->median($resolution),
        ];
    }

    /** @return array{requested: int, responses: int, responseRateBps: int|null, averageHundredths: int|null, satisfiedBps: int|null, distribution: array<int, int>} */
    private function csat(CarbonImmutable $start, CarbonImmutable $end, ?string $queueId): array
    {
        $scope = fn () => CsatSurvey::query()->when(
            $queueId !== null,
            fn ($query) => $query->whereIn('ticket_id', Ticket::query()->where('queue_id', $queueId)->select('id')),
        );

        $requested = $scope()->whereBetween('created_at', [$start, $end])->count();
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($scope()->where('status', SurveyStatus::Answered)->whereBetween('answered_at', [$start, $end])->cursor() as $survey) {
            if ($survey->rating !== null && isset($distribution[$survey->rating])) {
                $distribution[$survey->rating]++;
            }
        }

        $responses = array_sum($distribution);
        $sum = 0;

        foreach ($distribution as $rating => $count) {
            $sum += $rating * $count;
        }

        return [
            'requested' => $requested,
            'responses' => $responses,
            'responseRateBps' => $requested === 0 ? null : $this->rate(min($responses, $requested), $requested),
            'averageHundredths' => $responses === 0 ? null : intdiv($sum * 100 * 2 + $responses, $responses * 2),
            'satisfiedBps' => $this->rate($distribution[4] + $distribution[5], $responses),
            'distribution' => $distribution,
        ];
    }

    /** @return Builder<Ticket> */
    private function tickets(?string $queueId): Builder
    {
        return Ticket::query()
            ->where('status', '!=', TicketStatus::Merged)
            ->when($queueId !== null, fn ($query) => $query->where('queue_id', $queueId));
    }

    private function rate(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : intdiv($part * 10000 * 2 + $whole, $whole * 2);
    }

    /** @param list<int> $values */
    private function average(array $values): ?int
    {
        return $values === [] ? null : intdiv(array_sum($values) * 2 + count($values), count($values) * 2);
    }

    /** @param list<int> $values */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
