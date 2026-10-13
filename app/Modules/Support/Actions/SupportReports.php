<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Workspace-wide support reports. Dates are local dates in the workspace timezone; ratios are integer basis
 * points (10000 = 100%), average ratings are integer hundredths (450 = 4.50) and durations are whole minutes
 * of wall-clock time. Merged tickets are left out: their messages live on the surviving ticket.
 *
 * Every figure is computed by the database (GROUP BY, conditional SUM/COUNT, a LIMIT/OFFSET read of the middle
 * values for medians); no ticket is hydrated. Queries start from the tenant-scoped model, so the tenant filter
 * is the same one every other read uses.
 */
final class SupportReports
{
    public const int MAX_DAYS = 366;

    private const array EMPTY_VOLUME = ['created' => 0, 'resolved' => 0, 'byDay' => []];

    private const array EMPTY_BACKLOG = ['total' => 0, 'unassigned' => 0, 'byStatus' => [], 'byPriority' => [], 'byQueue' => [], 'aging' => []];

    private const array EMPTY_SLA = [
        'firstResponse' => ['met' => 0, 'missed' => 0, 'pending' => 0, 'rateBps' => null],
        'resolution' => ['met' => 0, 'missed' => 0, 'pending' => 0, 'rateBps' => null],
    ];

    private const array EMPTY_TIMES = ['firstResponseAverage' => null, 'firstResponseMedian' => null, 'resolutionAverage' => null, 'resolutionMedian' => null];

    private const array EMPTY_CSAT = ['requested' => 0, 'responses' => 0, 'responseRateBps' => null, 'averageHundredths' => null, 'satisfiedBps' => null, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];

    public function __construct(private readonly SupportSettings $settings) {}

    /**
     * @param  list<string>|null  $sections  volume, backlog, sla, times, csat; null computes all of them (the others are left empty)
     * @return array{
     *   range: array{from: string, to: string, days: int},
     *   volume: array{created: int, resolved: int, byDay: list<array{date: string, created: int, resolved: int}>},
     *   backlog: array{total: int, unassigned: int, byStatus: array<string, int>, byPriority: array<string, int>, byQueue: list<array{queueId: string|null, name: string|null, count: int}>, aging: array<string, int>},
     *   sla: array{firstResponse: array{met: int, missed: int, pending: int, rateBps: int|null}, resolution: array{met: int, missed: int, pending: int, rateBps: int|null}},
     *   times: array{firstResponseAverage: int|null, firstResponseMedian: int|null, resolutionAverage: int|null, resolutionMedian: int|null},
     *   csat: array{requested: int, responses: int, responseRateBps: int|null, averageHundredths: int|null, satisfiedBps: int|null, distribution: array<int, int>}
     * }
     */
    public function overview(string $from, string $to, ?string $queueId = null, ?array $sections = null): array
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

        $wants = static fn (string $section): bool => $sections === null || in_array($section, $sections, true);
        $slaAndTimes = $wants('sla') || $wants('times') ? $this->slaAndTimes($startUtc, $endUtc, $queueId) : null;

        return [
            'range' => ['from' => $from, 'to' => $to, 'days' => $days],
            'volume' => $wants('volume') ? $this->volume($startUtc, $endUtc, $zone, $queueId) : self::EMPTY_VOLUME,
            'backlog' => $wants('backlog') ? $this->backlog($queueId) : self::EMPTY_BACKLOG,
            'sla' => $slaAndTimes['sla'] ?? self::EMPTY_SLA,
            'times' => $slaAndTimes['times'] ?? self::EMPTY_TIMES,
            'csat' => $wants('csat') ? $this->csat($startUtc, $endUtc, $queueId) : self::EMPTY_CSAT,
        ];
    }

    /**
     * Counted per 15-minute UTC bucket in SQL (every zone offset is a whole number of quarter hours), then each
     * bucket is assigned to its local calendar day here, so daylight saving and half-hour zones stay exact.
     *
     * @return array{created: int, resolved: int, byDay: list<array{date: string, created: int, resolved: int}>}
     */
    private function volume(CarbonImmutable $start, CarbonImmutable $end, string $zone, ?string $queueId): array
    {
        $days = [];

        for ($day = $start->setTimezone($zone)->startOfDay(); $day->lessThanOrEqualTo($end); $day = $day->addDay()->startOfDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'created' => 0, 'resolved' => 0];
        }

        $totals = ['created' => 0, 'resolved' => 0];

        foreach (['created' => 'created_at', 'resolved' => 'resolved_at'] as $key => $column) {
            $rows = $this->tickets($queueId)->toBase()
                ->whereNotNull($column)
                ->whereBetween($column, [$start, $end])
                ->selectRaw("DATE_FORMAT({$column}, '%Y-%m-%d %H:00:00') as hour_start, FLOOR(MINUTE({$column}) / 15) as quarter, COUNT(*) as aggregate")
                ->groupBy('hour_start', 'quarter')
                ->get();

            foreach ($rows as $row) {
                $local = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row->hour_start, 'UTC')
                    ->addMinutes(15 * (int) $row->quarter)
                    ->setTimezone($zone)
                    ->toDateString();

                if (isset($days[$local])) {
                    $days[$local][$key] += (int) $row->aggregate;
                    $totals[$key] += (int) $row->aggregate;
                }
            }
        }

        return ['created' => $totals['created'], 'resolved' => $totals['resolved'], 'byDay' => array_values($days)];
    }

    /** @return array{total: int, unassigned: int, byStatus: array<string, int>, byPriority: array<string, int>, byQueue: list<array{queueId: string|null, name: string|null, count: int}>, aging: array<string, int>} */
    private function backlog(?string $queueId): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $byStatus = array_fill_keys(TicketStatus::activeValues(), 0);
        $byPriority = ['low' => 0, 'normal' => 0, 'high' => 0, 'urgent' => 0];
        $byQueue = [];
        $firstSeen = [];
        $bands = ['under_1_day', '1_to_3_days', '3_to_7_days', 'over_7_days'];
        $aging = array_fill_keys($bands, 0);
        $unassigned = 0;
        $total = 0;

        // One grouped read. Hours are whole hours truncated toward zero, like the former per-ticket (int) diff.
        $rows = $this->tickets($queueId)->toBase()
            ->whereIn('status', TicketStatus::activeValues())
            ->selectRaw(
                'status, priority, queue_id, (owner_id IS NULL) as unowned, '
                .'CASE WHEN TIMESTAMPDIFF(HOUR, created_at, ?) < 24 THEN 0 WHEN TIMESTAMPDIFF(HOUR, created_at, ?) < 72 THEN 1 '
                .'WHEN TIMESTAMPDIFF(HOUR, created_at, ?) < 168 THEN 2 ELSE 3 END as age_band, COUNT(*) as aggregate, MIN(id) as first_id',
                [$now, $now, $now],
            )
            ->groupBy('status', 'priority', 'queue_id', 'unowned', 'age_band')
            ->get();

        foreach ($rows as $row) {
            $count = (int) $row->aggregate;
            $queue = (string) ($row->queue_id ?? '');
            $firstId = (string) $row->first_id;
            $total += $count;
            $byStatus[(string) $row->status] += $count;
            $byPriority[(string) $row->priority] += $count;
            $byQueue[$queue] = ($byQueue[$queue] ?? 0) + $count;
            $firstSeen[$queue] = isset($firstSeen[$queue]) ? min($firstSeen[$queue], $firstId) : $firstId;
            $unassigned += (int) $row->unowned === 1 ? $count : 0;
            $aging[$bands[(int) $row->age_band]] += $count;
        }

        // Queues are listed in the order of their oldest ticket, as a scan of the table met them.
        asort($firstSeen);
        $names = Queue::query()->whereIn('id', array_values(array_filter(array_map('strval', array_keys($byQueue)), static fn (string $id): bool => $id !== '')))->pluck('name', 'id');
        $result = [];

        foreach (array_keys($firstSeen) as $key) {
            $id = (string) $key;
            $result[] = ['queueId' => $id === '' ? null : $id, 'name' => $id === '' ? null : ($names[$id] ?? null), 'count' => $byQueue[$id]];
        }

        return ['total' => $total, 'unassigned' => $unassigned, 'byStatus' => $byStatus, 'byPriority' => $byPriority, 'byQueue' => $result, 'aging' => $aging];
    }

    /**
     * SLA outcomes and times for the tickets created in the period: conditional sums for the outcomes, SUM/COUNT
     * for averages and one ordered, bounded read per metric for the medians.
     *
     * @return array{
     *   sla: array{firstResponse: array{met: int, missed: int, pending: int, rateBps: int|null}, resolution: array{met: int, missed: int, pending: int, rateBps: int|null}},
     *   times: array{firstResponseAverage: int|null, firstResponseMedian: int|null, resolutionAverage: int|null, resolutionMedian: int|null}
     * }
     */
    private function slaAndTimes(CarbonImmutable $start, CarbonImmutable $end, ?string $queueId): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $active = "'".implode("','", TicketStatus::activeValues())."'";
        $firstMinutes = 'GREATEST(0, TIMESTAMPDIFF(SECOND, created_at, first_responded_at) DIV 60)';
        $resolutionMinutes = 'GREATEST(0, TIMESTAMPDIFF(SECOND, created_at, resolved_at) DIV 60)';

        // met / missed for one clock (pending is the eligible remainder). Each "missed" carries one `?` (now).
        // $override is a boolean expression that forces "missed": the first resolution cycle's miss.
        $outcome = static fn (string $done, string $due, string $breached, string $override = '0'): array => [
            "({$override}) = 0 AND {$done} IS NOT NULL AND ({$due} IS NULL OR {$done} <= {$due})",
            "({$override}) = 1 OR ({$done} IS NOT NULL AND {$due} IS NOT NULL AND {$done} > {$due}) "
                ."OR ({$done} IS NULL AND ({$breached} IS NOT NULL OR ({$due} IS NOT NULL AND {$due} < ? AND status IN ({$active}))))",
        ];
        [$firstMet, $firstMissed] = $outcome('first_responded_at', 'first_response_due_at', 'first_response_breached_at');
        [$resolutionMet, $resolutionMissed] = $outcome('resolved_at', 'resolution_due_at', 'resolution_breached_at', 'resolution_first_breached_at IS NOT NULL');
        $firstEligible = 'sla_policy_id IS NOT NULL AND (first_response_due_at IS NOT NULL OR first_responded_at IS NOT NULL)';
        $resolutionEligible = 'sla_policy_id IS NOT NULL AND (resolution_due_at IS NOT NULL OR resolved_at IS NOT NULL)';

        $row = $this->tickets($queueId)->toBase()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$firstEligible} AND ({$firstMet}) THEN 1 ELSE 0 END), 0) as first_met, "
                ."COALESCE(SUM(CASE WHEN {$firstEligible} AND NOT ({$firstMet}) AND ({$firstMissed}) THEN 1 ELSE 0 END), 0) as first_missed, "
                ."COALESCE(SUM(CASE WHEN {$firstEligible} THEN 1 ELSE 0 END), 0) as first_eligible, "
                ."COALESCE(SUM(CASE WHEN {$resolutionEligible} AND ({$resolutionMet}) THEN 1 ELSE 0 END), 0) as resolution_met, "
                ."COALESCE(SUM(CASE WHEN {$resolutionEligible} AND NOT ({$resolutionMet}) AND ({$resolutionMissed}) THEN 1 ELSE 0 END), 0) as resolution_missed, "
                ."COALESCE(SUM(CASE WHEN {$resolutionEligible} THEN 1 ELSE 0 END), 0) as resolution_eligible, "
                .'COUNT(first_responded_at) as first_count, '
                ."COALESCE(SUM(CASE WHEN first_responded_at IS NOT NULL THEN {$firstMinutes} END), 0) as first_sum, "
                .'COUNT(resolved_at) as resolution_count, '
                ."COALESCE(SUM(CASE WHEN resolved_at IS NOT NULL THEN {$resolutionMinutes} END), 0) as resolution_sum",
                [$now, $now],
            )
            ->first();

        $first = ['met' => (int) $row->first_met, 'missed' => (int) $row->first_missed];
        $first['pending'] = (int) $row->first_eligible - $first['met'] - $first['missed'];
        $resolution = ['met' => (int) $row->resolution_met, 'missed' => (int) $row->resolution_missed];
        $resolution['pending'] = (int) $row->resolution_eligible - $resolution['met'] - $resolution['missed'];

        return [
            'sla' => [
                'firstResponse' => $first + ['rateBps' => $this->rate($first['met'], $first['met'] + $first['missed'])],
                'resolution' => $resolution + ['rateBps' => $this->rate($resolution['met'], $resolution['met'] + $resolution['missed'])],
            ],
            'times' => [
                'firstResponseAverage' => $this->average((int) $row->first_sum, (int) $row->first_count),
                'firstResponseMedian' => $this->median($start, $end, $queueId, 'first_responded_at', $firstMinutes, (int) $row->first_count),
                'resolutionAverage' => $this->average((int) $row->resolution_sum, (int) $row->resolution_count),
                'resolutionMedian' => $this->median($start, $end, $queueId, 'resolved_at', $resolutionMinutes, (int) $row->resolution_count),
            ],
        ];
    }

    /** The one or two middle values of the ordered durations, read with LIMIT/OFFSET; the rest is never loaded. */
    private function median(CarbonImmutable $start, CarbonImmutable $end, ?string $queueId, string $column, string $minutes, int $count): ?int
    {
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);
        $odd = $count % 2 === 1;
        $values = $this->tickets($queueId)->toBase()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull($column)
            ->selectRaw("{$minutes} as minutes")
            ->orderByRaw($minutes)
            ->offset($odd ? $middle : $middle - 1)
            ->limit($odd ? 1 : 2)
            ->pluck('minutes')
            ->map(static fn (mixed $value): int => (int) $value)
            ->values()
            ->all();

        if ($values === []) {
            return null;
        }

        return count($values) === 1 ? $values[0] : intdiv($values[0] + $values[1], 2);
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

        $answered = $scope()->toBase()
            ->where('status', SurveyStatus::Answered->value)
            ->whereBetween('answered_at', [$start, $end])
            ->whereIn('rating', array_keys($distribution))
            ->selectRaw('rating, COUNT(*) as aggregate')
            ->groupBy('rating')
            ->get();

        foreach ($answered as $row) {
            $distribution[(int) $row->rating] += (int) $row->aggregate;
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
            ->where('status', '!=', TicketStatus::Merged->value)
            ->when($queueId !== null, fn ($query) => $query->where('queue_id', $queueId));
    }

    private function rate(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : intdiv($part * 10000 * 2 + $whole, $whole * 2);
    }

    private function average(int $sum, int $count): ?int
    {
        return $count === 0 ? null : intdiv($sum * 2 + $count, $count * 2);
    }
}
