<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\InterviewMode;
use App\Modules\Talent\Enums\InterviewOutcome;
use App\Modules\Talent\Enums\InterviewStatus;
use App\Modules\Talent\Enums\Recommendation;
use App\Modules\Talent\Events\InterviewCompleted;
use App\Modules\Talent\Events\InterviewScheduled;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\InterviewPanelist;
use App\Modules\Talent\Models\Scorecard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Interview scheduling and panel feedback. Times are entered as a local date and time in the
 * workspace timezone, stored as UTC instants together with the zone name.
 */
final class InterviewManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TalentSettings $settings,
        private readonly EmployeeLookup $employees,
    ) {}

    /**
     * @param  array{round_name: string, mode: string, date: string, time: string, duration_minutes: int|string, location?: string|null, notes?: string|null, panel: list<string>}  $attributes
     */
    public function schedule(Application $application, array $attributes, ?TenantMembership $actor): Interview
    {
        [$start, $end] = $this->window($attributes);
        $panel = $this->resolvePanel($attributes['panel']);

        return DB::transaction(function () use ($application, $attributes, $actor, $start, $end, $panel): Interview {
            /** @var Application $locked */
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ApplicationStatus::Open) {
                throw ValidationException::withMessages(['application_id' => __('Interviews can only be scheduled for an open application.')]);
            }

            $this->guardConflicts($panel, $start, $end, null);

            $interview = new Interview([
                'application_id' => $locked->id,
                'round_name' => $attributes['round_name'],
                'mode' => InterviewMode::from($attributes['mode']),
                'starts_at' => $start,
                'ends_at' => $end,
                'timezone' => $this->settings->timezone(),
                'location' => $attributes['location'] ?? null,
                'status' => InterviewStatus::Scheduled,
                'notes' => $attributes['notes'] ?? null,
                'scheduled_by' => $actor?->id,
            ]);
            $interview->copyPlacement($locked)->save();

            foreach ($panel as $employeeId) {
                InterviewPanelist::query()->create(['interview_id' => $interview->id, 'employee_id' => $employeeId]);
            }

            $this->audit->record('talent.interview.scheduled', $interview, ['starts_at' => $start->toIso8601String(), 'panel' => count($panel)]);
            InterviewScheduled::dispatch([
                'interview_id' => $interview->id,
                'application_id' => $locked->id,
                'candidate_id' => $locked->candidate_id,
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'timezone' => $interview->timezone,
                'panel_employee_ids' => implode(',', $panel),
            ]);

            return $interview;
        });
    }

    /**
     * @param  array{round_name: string, mode: string, date: string, time: string, duration_minutes: int|string, location?: string|null, notes?: string|null, panel: list<string>}  $attributes
     */
    public function reschedule(Interview $interview, array $attributes): Interview
    {
        [$start, $end] = $this->window($attributes);
        $panel = $this->resolvePanel($attributes['panel']);

        return DB::transaction(function () use ($interview, $attributes, $start, $end, $panel): Interview {
            /** @var Interview $locked */
            $locked = Interview::query()->whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InterviewStatus::Scheduled) {
                throw ValidationException::withMessages(['status' => __('Only a scheduled interview can be changed.')]);
            }

            $this->guardConflicts($panel, $start, $end, $locked->id);

            $locked->fill([
                'round_name' => $attributes['round_name'],
                'mode' => InterviewMode::from($attributes['mode']),
                'starts_at' => $start,
                'ends_at' => $end,
                'timezone' => $this->settings->timezone(),
                'location' => $attributes['location'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ])->save();

            $existing = InterviewPanelist::query()->where('interview_id', $locked->id)->pluck('employee_id')->all();
            InterviewPanelist::query()->where('interview_id', $locked->id)->whereNotIn('employee_id', $panel)->delete();

            foreach (array_diff($panel, $existing) as $employeeId) {
                InterviewPanelist::query()->create(['interview_id' => $locked->id, 'employee_id' => $employeeId]);
            }

            $this->audit->record('talent.interview.rescheduled', $locked, ['starts_at' => $start->toIso8601String()]);

            return $locked;
        });
    }

    public function cancel(Interview $interview, ?string $reason): Interview
    {
        return $this->finish($interview, InterviewStatus::Cancelled, null, $reason, requireStarted: false);
    }

    public function complete(Interview $interview, InterviewOutcome $outcome, ?string $notes): Interview
    {
        return $this->finish($interview, InterviewStatus::Completed, $outcome, $notes, requireStarted: true);
    }

    public function markNoShow(Interview $interview, ?string $notes): Interview
    {
        return $this->finish($interview, InterviewStatus::NoShow, null, $notes, requireStarted: true);
    }

    /**
     * A panelist's scorecard (one per panelist and interview; resubmitting replaces it until the
     * interview is completed).
     *
     * @param  array{rating: int|string, recommendation: string, strengths?: string|null, concerns?: string|null}  $attributes
     */
    public function submitScorecard(Interview $interview, string $employeeId, array $attributes): Scorecard
    {
        return DB::transaction(function () use ($interview, $employeeId, $attributes): Scorecard {
            /** @var Interview $locked */
            $locked = Interview::query()->whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if (InterviewPanelist::query()->where('interview_id', $locked->id)->where('employee_id', $employeeId)->doesntExist()) {
                throw ValidationException::withMessages(['employee_id' => __('Only a member of the interview panel can submit a scorecard.')]);
            }

            if (! in_array($locked->status, [InterviewStatus::Scheduled, InterviewStatus::Completed], true)) {
                throw ValidationException::withMessages(['status' => __('This interview did not take place.')]);
            }

            if ($locked->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => __('A scorecard can be submitted once the interview has started.')]);
            }

            $existing = Scorecard::query()->where('interview_id', $locked->id)->where('employee_id', $employeeId)->first();

            if ($existing !== null && $locked->status === InterviewStatus::Completed) {
                throw ValidationException::withMessages(['status' => __('The interview is completed; its scorecards are final.')]);
            }

            $values = [
                'application_id' => $locked->application_id,
                'rating' => (int) $attributes['rating'],
                'recommendation' => Recommendation::from($attributes['recommendation']),
                'strengths' => $attributes['strengths'] ?? null,
                'concerns' => $attributes['concerns'] ?? null,
                'submitted_at' => now(),
            ];

            if ($existing !== null) {
                $existing->fill($values)->save();

                return $existing;
            }

            $card = new Scorecard([...$values, 'interview_id' => $locked->id, 'employee_id' => $employeeId]);
            $card->save();
            $this->audit->record('talent.scorecard.submitted', $locked, ['rating' => $card->rating]);

            return $card;
        });
    }

    private function finish(Interview $interview, InterviewStatus $to, ?InterviewOutcome $outcome, ?string $notes, bool $requireStarted): Interview
    {
        return DB::transaction(function () use ($interview, $to, $outcome, $notes, $requireStarted): Interview {
            /** @var Interview $locked */
            $locked = Interview::query()->whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InterviewStatus::Scheduled) {
                throw ValidationException::withMessages(['status' => __('Only a scheduled interview can be changed.')]);
            }

            if ($requireStarted && $locked->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => __('The interview has not started yet.')]);
            }

            $locked->status = $to;
            $locked->outcome = $outcome;
            $locked->notes = $notes ?? $locked->notes;
            $locked->completed_at = $to === InterviewStatus::Cancelled ? null : now();
            $locked->save();
            $this->audit->record('talent.interview.'.$to->value, $locked, ['outcome' => $outcome?->value]);

            if ($to === InterviewStatus::Completed) {
                InterviewCompleted::dispatch([
                    'interview_id' => $locked->id, 'application_id' => $locked->application_id, 'outcome' => $outcome?->value,
                ]);
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $attributes): array
    {
        $minutes = (int) $attributes['duration_minutes'];

        if ($minutes < 15 || $minutes > 480) {
            throw ValidationException::withMessages(['duration_minutes' => __('An interview lasts between 15 minutes and 8 hours.')]);
        }

        $start = $this->settings->toUtc((string) $attributes['date'], (string) $attributes['time']);

        if ($start->isPast()) {
            throw ValidationException::withMessages(['date' => __('An interview cannot be scheduled in the past.')]);
        }

        return [$start, $start->addMinutes($minutes)];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function resolvePanel(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === [] || count($ids) > 10) {
            throw ValidationException::withMessages(['panel' => __('Choose between 1 and 10 interviewers.')]);
        }

        $found = $this->employees->many($ids);

        foreach ($ids as $id) {
            if (! isset($found[$id]) || $found[$id]->status === 'terminated') {
                throw ValidationException::withMessages(['panel' => __('Every interviewer must be an employee of this workspace.')]);
            }
        }

        return $ids;
    }

    /** @param  list<string>  $panel */
    private function guardConflicts(array $panel, CarbonImmutable $start, CarbonImmutable $end, ?string $exceptId): void
    {
        $busy = InterviewPanelist::query()
            ->whereIn('employee_id', $panel)
            ->whereIn('interview_id', Interview::query()
                ->where('status', InterviewStatus::Scheduled)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
                ->select('id'))
            ->exists();

        if ($busy) {
            throw ValidationException::withMessages(['panel' => __('An interviewer already has an interview at that time.')]);
        }
    }
}
