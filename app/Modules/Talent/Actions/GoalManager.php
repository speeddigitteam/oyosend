<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\GoalKind;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Events\GoalAchieved;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\GoalUpdate;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Support\GoalProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Goals and KPIs with integer targets and an append-only progress history. */
final class GoalManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TalentSettings $settings,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, kind?: string, unit?: string|null, start_value?: int|string|null, target_value: int|string, due_on?: string|null, cycle_id?: string|null}  $attributes
     */
    public function create(EmployeeSnapshot $employee, array $attributes, ?TenantMembership $actor): Goal
    {
        $start = (int) ($attributes['start_value'] ?? 0);
        $target = (int) $attributes['target_value'];

        if ($start === $target) {
            throw ValidationException::withMessages(['target_value' => __('The target must differ from the starting value.')]);
        }

        $cycleId = isset($attributes['cycle_id']) && $attributes['cycle_id'] !== '' ? (string) $attributes['cycle_id'] : null;

        if ($cycleId !== null) {
            $cycle = ReviewCycle::query()->find($cycleId);

            if ($cycle === null || $cycle->status === CycleStatus::Closed) {
                throw ValidationException::withMessages(['cycle_id' => __('Choose an open review cycle.')]);
            }
        }

        return DB::transaction(function () use ($employee, $attributes, $actor, $start, $target, $cycleId): Goal {
            $goal = new Goal([
                'employee_id' => $employee->id,
                'cycle_id' => $cycleId,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'kind' => GoalKind::tryFrom((string) ($attributes['kind'] ?? '')) ?? GoalKind::Goal,
                'unit' => $attributes['unit'] ?? null,
                'start_value' => $start,
                'target_value' => $target,
                'current_value' => $start,
                'status' => GoalStatus::Active,
                'due_on' => $attributes['due_on'] ?? null,
                'created_by' => $actor?->id,
            ]);
            $goal->placeForEmployee($employee)->save();

            return $goal;
        });
    }

    /** @param array{title: string, description?: string|null, unit?: string|null, target_value: int|string, due_on?: string|null} $attributes */
    public function update(Goal $goal, array $attributes): Goal
    {
        if ($goal->status !== GoalStatus::Active) {
            throw ValidationException::withMessages(['title' => __('Only an active goal can be edited.')]);
        }

        $target = (int) $attributes['target_value'];

        if ($target === $goal->start_value) {
            throw ValidationException::withMessages(['target_value' => __('The target must differ from the starting value.')]);
        }

        $goal->fill([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'unit' => $attributes['unit'] ?? null,
            'target_value' => $target,
            'due_on' => $attributes['due_on'] ?? null,
        ])->save();

        return $goal;
    }

    public function recordProgress(Goal $goal, int $value, ?string $note, ?TenantMembership $actor): Goal
    {
        return DB::transaction(function () use ($goal, $value, $note, $actor): Goal {
            /** @var Goal $locked */
            $locked = Goal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GoalStatus::Active) {
                throw ValidationException::withMessages(['value' => __('Progress can only be recorded on an active goal.')]);
            }

            GoalUpdate::query()->create([
                'goal_id' => $locked->id,
                'value' => $value,
                'note' => $note,
                'recorded_on' => $this->settings->today()->toDateString(),
                'recorded_by' => $actor?->id,
            ]);

            $locked->current_value = $value;

            if (GoalProgress::reached($locked->start_value, $locked->target_value, $value)) {
                $this->markAchieved($locked);
            }

            $locked->save();

            return $locked;
        });
    }

    public function setStatus(Goal $goal, GoalStatus $to): Goal
    {
        return DB::transaction(function () use ($goal, $to): Goal {
            /** @var Goal $locked */
            $locked = Goal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GoalStatus::Active || $to === GoalStatus::Active) {
                throw ValidationException::withMessages(['status' => __('Only an active goal can be closed.')]);
            }

            if ($to === GoalStatus::Achieved) {
                $this->markAchieved($locked);
            } else {
                $locked->status = $to;
            }

            $locked->save();
            $this->audit->record('talent.goal.'.$to->value, $locked);

            return $locked;
        });
    }

    private function markAchieved(Goal $goal): void
    {
        $goal->status = GoalStatus::Achieved;
        $goal->achieved_at = now();
        GoalAchieved::dispatch([
            'goal_id' => $goal->id, 'employee_id' => $goal->employee_id, 'membership_id' => $goal->owner_id, 'kind' => $goal->kind->value,
        ]);
    }
}
