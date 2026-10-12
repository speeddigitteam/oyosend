<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Modules\Talent\Enums\ApplicationEvent;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\ApplicationHistory;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR analytics. Workspace-wide read models over Talent's own tables and, for headcount and attrition,
 * the employee directory (the single source of truth for who is employed when).
 *
 * Rates are integer basis points (10 000 = 100%) and durations whole days rounded half up; no float
 * is stored or sent.
 */
final class TalentAnalytics
{
    public function __construct(
        private readonly TalentSettings $settings,
        private readonly EmployeeLookup $employees,
    ) {}

    /**
     * Recruitment: how many applications reached each stage, outcomes, and time to fill / hire.
     *
     * @return array<string, mixed>
     */
    public function recruitment(?string $from = null, ?string $to = null): array
    {
        $applications = Application::query()
            ->when($from !== null, fn ($query) => $query->where('applied_on', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('applied_on', '<=', $to));

        $byStatus = (clone $applications)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
        $ids = (clone $applications)->select('id');

        // "Reached" counts each application once per stage it ever entered (the applying stage included).
        $reached = ApplicationHistory::query()
            ->whereIn('application_id', $ids)
            ->whereNotNull('to_stage_id')
            ->whereIn('event', [ApplicationEvent::Applied, ApplicationEvent::Moved, ApplicationEvent::Hired, ApplicationEvent::Rejected, ApplicationEvent::Reopened])
            ->select('to_stage_id', DB::raw('count(distinct application_id) as total'))
            ->groupBy('to_stage_id')->pluck('total', 'to_stage_id')->all();

        $stages = PipelineStage::query()->whereIn('id', array_keys($reached))->get()->groupBy('name');
        $funnel = [];

        foreach ($stages as $name => $group) {
            $funnel[] = [
                'stage' => (string) $name,
                'type' => $group->first()->type->value,
                'position' => (int) $group->min('position'),
                'reached' => (int) $group->sum(static fn (PipelineStage $stage): int => (int) ($reached[$stage->id] ?? 0)),
            ];
        }

        usort($funnel, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $total = (int) array_sum($byStatus);
        $hired = (int) ($byStatus[ApplicationStatus::Hired->value] ?? 0);

        return [
            'applications' => [
                'total' => $total,
                'open' => (int) ($byStatus[ApplicationStatus::Open->value] ?? 0),
                'hired' => $hired,
                'rejected' => (int) ($byStatus[ApplicationStatus::Rejected->value] ?? 0),
                'withdrawn' => (int) ($byStatus[ApplicationStatus::Withdrawn->value] ?? 0),
                'hireRateBp' => $total === 0 ? 0 : intdiv($hired * 10000, $total),
            ],
            'funnel' => $funnel,
            'openings' => [
                'open' => JobOpening::query()->where('status', OpeningStatus::Open)->count(),
                'onHold' => JobOpening::query()->where('status', OpeningStatus::OnHold)->count(),
                'closed' => JobOpening::query()->where('status', OpeningStatus::Closed)->count(),
                'draft' => JobOpening::query()->where('status', OpeningStatus::Draft)->count(),
            ],
            'timeToFillDays' => $this->timeToFill($from, $to),
            'timeToHireDays' => $this->timeToHire($from, $to),
        ];
    }

    /** Average days from an opening being opened to its last hire, over openings that hired. */
    private function timeToFill(?string $from, ?string $to): ?int
    {
        $rows = Application::query()
            ->join('talent_job_openings as o', 'o.id', '=', 'talent_applications.opening_id')
            ->where('talent_applications.status', ApplicationStatus::Hired)
            ->when($from !== null, fn ($query) => $query->where('talent_applications.hired_at', '>=', $from.' 00:00:00'))
            ->when($to !== null, fn ($query) => $query->where('talent_applications.hired_at', '<=', $to.' 23:59:59'))
            ->groupBy('o.id', 'o.opened_at', 'o.created_at')
            ->select('o.id', 'o.opened_at', 'o.created_at', DB::raw('max(talent_applications.hired_at) as last_hired_at'))
            ->get();

        return $this->averageDays($rows->map(static fn ($row): array => [
            CarbonImmutable::parse((string) ($row->opened_at ?? $row->created_at), 'UTC'),
            CarbonImmutable::parse((string) $row->getAttribute('last_hired_at'), 'UTC'),
        ])->all());
    }

    private function timeToHire(?string $from, ?string $to): ?int
    {
        $hired = Application::query()->where('status', ApplicationStatus::Hired)->whereNotNull('hired_at')
            ->when($from !== null, fn ($query) => $query->where('hired_at', '>=', $from.' 00:00:00'))
            ->when($to !== null, fn ($query) => $query->where('hired_at', '<=', $to.' 23:59:59'))
            ->get(['applied_on', 'hired_at']);

        return $this->averageDays($hired->map(static fn (Application $application): array => [
            CarbonImmutable::parse($application->applied_on->toDateString(), 'UTC'),
            CarbonImmutable::parse($application->hired_at?->toDateTimeString() ?? $application->applied_on->toDateString(), 'UTC'),
        ])->all());
    }

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $pairs */
    private function averageDays(array $pairs): ?int
    {
        if ($pairs === []) {
            return null;
        }

        $seconds = 0;

        foreach ($pairs as [$start, $end]) {
            $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
        }

        // Round half up on whole days, in integers.
        return intdiv(2 * $seconds + 86400 * count($pairs), 2 * 86400 * count($pairs));
    }

    /**
     * Headcount per month end and attrition for the last `$months` months (ending with this month).
     *
     * @return array{months: list<array<string, mixed>>, attritionBp: int, leavers: int, headcount: int}
     */
    public function workforce(int $months = 12): array
    {
        $months = max(1, min(36, $months));
        $today = $this->settings->today();
        $firstMonth = CarbonImmutable::parse($today->format('Y-m-01'), 'UTC')->subMonthsNoOverflow($months - 1);

        $people = [];

        foreach ($this->employees->each() as $employee) {
            $people[] = [$employee->joinedOn, $employee->terminatedOn];
        }

        $series = [];
        $leaversTotal = 0;
        $sumAverage = 0;

        for ($i = 0; $i < $months; $i++) {
            $start = $firstMonth->addMonthsNoOverflow($i);
            $end = $start->endOfMonth();
            $endDate = $end->toDateString() > $today->toDateString() ? $today->toDateString() : $end->toDateString();
            $startDate = $start->toDateString();
            $openingDate = $start->subDay()->toDateString();

            $headcount = 0;
            $opening = 0;
            $joiners = 0;
            $leavers = 0;

            foreach ($people as [$joined, $terminated]) {
                if ($joined <= $endDate && ($terminated === null || $terminated > $endDate)) {
                    $headcount++;
                }

                if ($joined <= $openingDate && ($terminated === null || $terminated > $openingDate)) {
                    $opening++;
                }

                if ($joined >= $startDate && $joined <= $endDate) {
                    $joiners++;
                }

                if ($terminated !== null && $terminated >= $startDate && $terminated <= $endDate) {
                    $leavers++;
                }
            }

            $average2 = $opening + $headcount; // twice the average headcount
            $series[] = [
                'month' => $start->format('Y-m'),
                'headcount' => $headcount,
                'joiners' => $joiners,
                'leavers' => $leavers,
                'attritionBp' => $average2 === 0 ? 0 : intdiv($leavers * 20000, $average2),
            ];
            $leaversTotal += $leavers;
            $sumAverage += $average2;
        }

        return [
            'months' => $series,
            'leavers' => $leaversTotal,
            // Leavers over the average headcount of the window, in basis points.
            'attritionBp' => $sumAverage === 0 ? 0 : intdiv($leaversTotal * 20000 * $months, $sumAverage),
            'headcount' => $series[array_key_last($series)]['headcount'],
        ];
    }

    /**
     * Review completion per cycle: counts by state and the completion share in basis points.
     *
     * @return list<array<string, mixed>>
     */
    public function reviews(int $limit = 12): array
    {
        $cycles = ReviewCycle::query()->orderByDesc('starts_on')->limit($limit)->get();
        $counts = Review::query()->whereIn('cycle_id', $cycles->pluck('id'))
            ->select('cycle_id', 'status', DB::raw('count(*) as total'))->groupBy('cycle_id', 'status')->get()
            ->groupBy('cycle_id');

        return $cycles->map(function (ReviewCycle $cycle) use ($counts): array {
            $byStatus = ($counts[$cycle->id] ?? collect())->pluck('total', 'status')->map(static fn ($total): int => (int) $total)->all();
            $total = array_sum($byStatus);
            $done = (int) (($byStatus['calibrated'] ?? 0) + ($byStatus['acknowledged'] ?? 0));

            return [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status->value,
                'total' => $total,
                'byStatus' => $byStatus,
                'completionBp' => $total === 0 ? 0 : intdiv($done * 10000, $total),
                'isOpen' => $cycle->status !== CycleStatus::Closed,
            ];
        })->all();
    }

    /**
     * Training completion and certificate health.
     *
     * @return array<string, mixed>
     */
    public function training(): array
    {
        $today = $this->settings->today();
        $byStatus = Enrolment::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
        $completed = (int) ($byStatus[EnrolmentStatus::Completed->value] ?? 0);
        $finished = $completed + (int) ($byStatus[EnrolmentStatus::Failed->value] ?? 0);
        $window = $this->settings->current()->certification_reminder_days;

        return [
            'enrolments' => [
                'enrolled' => (int) ($byStatus[EnrolmentStatus::Enrolled->value] ?? 0),
                'completed' => $completed,
                'failed' => (int) ($byStatus[EnrolmentStatus::Failed->value] ?? 0),
                'cancelled' => (int) ($byStatus[EnrolmentStatus::Cancelled->value] ?? 0),
            ],
            'completionRateBp' => $finished === 0 ? 0 : intdiv($completed * 10000, $finished),
            'courses' => Course::query()->where('is_active', true)->count(),
            'certificatesExpiring' => Enrolment::query()->where('status', EnrolmentStatus::Completed)
                ->where('expires_on', '>=', $today->toDateString())
                ->where('expires_on', '<=', $today->addDays($window)->toDateString())->count(),
            'certificatesExpired' => Enrolment::query()->where('status', EnrolmentStatus::Completed)
                ->where('expires_on', '<', $today->toDateString())->count(),
        ];
    }
}
