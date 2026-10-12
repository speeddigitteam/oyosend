<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Enums\ProbationDecision;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Events\ProbationDecisionRecorded;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\ProbationReview;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Probation tracking. The employee's probation end date and status belong to HRM and are mirrored
 * here (from events and from the employee directory); a decision recorded here is a
 * recommendation announced with `talent.probation.decision_recorded`. HRM changes the status.
 */
final class ProbationManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TalentSettings $settings,
    ) {}

    /** Start tracking an employee who is on probation. Idempotent. */
    public function track(EmployeeSnapshot $employee): ?Probation
    {
        if ($employee->status !== 'probation') {
            return null;
        }

        $open = Probation::query()->where('employee_id', $employee->id)->where('status', ProbationStatus::InProgress)->first();

        if ($open !== null) {
            return $this->sync($open, $employee);
        }

        $ends = $employee->probationEndsOn
            ?? CarbonImmutable::parse($employee->joinedOn, 'UTC')->addDays($this->settings->current()->default_probation_days)->toDateString();

        try {
            return DB::transaction(function () use ($employee, $ends): Probation {
                $probation = new Probation([
                    'employee_id' => $employee->id,
                    'started_on' => $employee->joinedOn,
                    'ends_on' => $ends,
                    'observed_ends_on' => $employee->probationEndsOn,
                    'status' => ProbationStatus::InProgress,
                    'open_marker' => 1,
                ]);
                $probation->placeForEmployee($employee)->save();

                return $probation;
            });
        } catch (UniqueConstraintViolationException) {
            return Probation::query()->where('employee_id', $employee->id)->where('status', ProbationStatus::InProgress)->first();
        }
    }

    /**
     * Bring a probation in line with what HRM says now: a new end date replaces ours, a confirmed or
     * terminated employee closes it.
     */
    public function sync(Probation $probation, ?EmployeeSnapshot $employee): Probation
    {
        if ($probation->status !== ProbationStatus::InProgress || $employee === null) {
            return $probation;
        }

        if ($employee->status === 'terminated') {
            return $this->close($probation, ProbationStatus::Ended, $employee->terminatedOn);
        }

        if ($employee->status !== 'probation') {
            return $this->close($probation, ProbationStatus::Confirmed, $employee->confirmedOn);
        }

        $observed = $employee->probationEndsOn;

        if ($observed !== null && $observed !== $probation->observed_ends_on?->toDateString()) {
            $probation->forceFill(['ends_on' => $observed, 'observed_ends_on' => $observed])->save();
        }

        return $probation;
    }

    public function close(Probation $probation, ProbationStatus $status, ?string $on = null): Probation
    {
        if ($probation->status !== ProbationStatus::InProgress) {
            return $probation;
        }

        $probation->status = $status;
        $probation->open_marker = null;
        $probation->setAttribute('closed_on', $on ?? $this->settings->today()->toDateString());
        $probation->save();
        $this->audit->record('talent.probation.'.$status->value, $probation, ['employee_id' => $probation->employee_id]);

        return $probation;
    }

    /** @param array{decision: string, note?: string|null, extend_until?: string|null} $attributes */
    public function recordDecision(Probation $probation, array $attributes, ?TenantMembership $actor): ProbationReview
    {
        $decision = ProbationDecision::from($attributes['decision']);

        return DB::transaction(function () use ($probation, $attributes, $actor, $decision): ProbationReview {
            /** @var Probation $locked */
            $locked = Probation::query()->whereKey($probation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProbationStatus::InProgress) {
                throw ValidationException::withMessages(['decision' => __('This probation is already closed.')]);
            }

            $extendUntil = null;

            if ($decision === ProbationDecision::Extend) {
                $extendUntil = (string) ($attributes['extend_until'] ?? '');

                if ($extendUntil === '' || $extendUntil <= $locked->ends_on->toDateString()) {
                    throw ValidationException::withMessages(['extend_until' => __('Choose a date after the current probation end.')]);
                }

                $locked->forceFill(['ends_on' => $extendUntil])->save();
            }

            $review = ProbationReview::query()->create([
                'probation_id' => $locked->id,
                'decision' => $decision,
                'extend_until' => $extendUntil,
                'note' => $attributes['note'] ?? null,
                'decided_by' => $actor?->id,
                'decided_on' => $this->settings->today()->toDateString(),
            ]);
            $this->audit->record('talent.probation.decision_recorded', $locked, ['decision' => $decision->value, 'extend_until' => $extendUntil]);
            ProbationDecisionRecorded::dispatch([
                'probation_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'membership_id' => $locked->owner_id,
                'decision' => $decision->value,
                'extend_until' => $extendUntil,
            ]);

            return $review;
        });
    }

    /** Whether a final (confirm or end) decision was recorded, so reminders can stop. */
    public function hasFinalDecision(Probation $probation): bool
    {
        return ProbationReview::query()->where('probation_id', $probation->id)
            ->whereIn('decision', [ProbationDecision::Confirm, ProbationDecision::End])->exists();
    }
}
