<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Department;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Events\ReviewCycleLaunched;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewForm;
use App\Modules\Talent\Models\ReviewFormItem;
use App\Modules\Talent\Support\ReviewScoring;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Review forms (weighted competencies) and the review cycle lifecycle: draft, active, calibration, closed. */
final class ReviewCycleManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeLookup $employees,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, scale_max: int|string, is_active?: bool, items?: list<array{name: string, description?: string|null, weight_bp: int|string}>}  $attributes
     */
    public function saveForm(?ReviewForm $form, array $attributes): ReviewForm
    {
        $scale = (int) $attributes['scale_max'];

        if ($scale < 3 || $scale > 10) {
            throw ValidationException::withMessages(['scale_max' => __('The rating scale goes up to a value between 3 and 10.')]);
        }

        $items = $attributes['items'] ?? [];

        if (array_key_exists('items', $attributes)) {
            if ($items === [] || count($items) > 30) {
                throw ValidationException::withMessages(['items' => __('A form needs between 1 and 30 competencies.')]);
            }

            $total = array_sum(array_map(static fn (array $item): int => (int) $item['weight_bp'], $items));

            if ($total !== ReviewScoring::TOTAL_WEIGHT) {
                throw ValidationException::withMessages(['items' => __('The weights must add up to exactly 100% (10000 basis points).')]);
            }
        } elseif ($form === null) {
            throw ValidationException::withMessages(['items' => __('A form needs between 1 and 30 competencies.')]);
        }

        return DB::transaction(function () use ($form, $attributes, $scale, $items): ReviewForm {
            $used = $form !== null && ReviewCycle::query()->where('form_id', $form->id)->where('status', '!=', CycleStatus::Draft)->exists();

            if ($used && ($scale !== $form->scale_max || array_key_exists('items', $attributes))) {
                throw ValidationException::withMessages(['items' => __('A form used by a launched cycle can only be renamed or deactivated.')]);
            }

            $form ??= new ReviewForm;
            $form->fill([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'scale_max' => $scale,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);

            try {
                $form->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('A form with that name already exists.')]);
            }

            if (array_key_exists('items', $attributes)) {
                ReviewFormItem::query()->where('form_id', $form->id)->delete();

                foreach ($items as $position => $item) {
                    ReviewFormItem::query()->create([
                        'form_id' => $form->id,
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'weight_bp' => (int) $item['weight_bp'],
                        'position' => ($position + 1) * 10,
                    ]);
                }
            }

            return $form;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function saveCycle(?ReviewCycle $cycle, array $attributes, ?TenantMembership $actor): ReviewCycle
    {
        if ($cycle !== null && $cycle->status !== CycleStatus::Draft) {
            throw ValidationException::withMessages(['name' => __('Only a draft cycle can be edited.')]);
        }

        $form = ReviewForm::query()->find((string) $attributes['form_id']);

        if ($form === null || ! $form->is_active) {
            throw ValidationException::withMessages(['form_id' => __('Choose an active review form.')]);
        }

        $department = isset($attributes['department_id']) && $attributes['department_id'] !== '' ? (string) $attributes['department_id'] : null;

        if ($department !== null && Department::query()->whereKey($department)->doesntExist()) {
            throw ValidationException::withMessages(['department_id' => __('Choose a department of this workspace.')]);
        }

        if ((string) $attributes['ends_on'] < (string) $attributes['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => __('The cycle cannot end before it starts.')]);
        }

        $cycle ??= new ReviewCycle;
        $cycle->fill([
            'name' => $attributes['name'],
            'form_id' => $form->id,
            'starts_on' => $attributes['starts_on'],
            'ends_on' => $attributes['ends_on'],
            'self_due_on' => $attributes['self_due_on'] ?? null,
            'manager_due_on' => $attributes['manager_due_on'] ?? null,
            'requires_self_review' => (bool) ($attributes['requires_self_review'] ?? true),
            'department_id' => $department,
        ]);

        if (! $cycle->exists) {
            $cycle->status = CycleStatus::Draft;
            $cycle->created_by = $actor?->id;
        }

        $cycle->save();

        return $cycle;
    }

    /** Create one review per working employee in scope. Idempotent per (cycle, employee). */
    public function launch(ReviewCycle $cycle, ?TenantMembership $actor): ReviewCycle
    {
        return DB::transaction(function () use ($cycle, $actor): ReviewCycle {
            /** @var ReviewCycle $locked */
            $locked = ReviewCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CycleStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft cycle can be launched.')]);
            }

            $filters = ['status' => ['probation', 'active', 'on_leave'], 'active_on' => $locked->starts_on->toDateString()];

            if ($locked->department_id !== null) {
                $filters['department_id'] = $locked->department_id;
            }

            $created = 0;
            $first = $locked->requires_self_review ? ReviewStatus::PendingSelf : ReviewStatus::PendingManager;

            foreach ($this->employees->each($filters) as $employee) {
                $review = new Review([
                    'cycle_id' => $locked->id,
                    'employee_id' => $employee->id,
                    'reviewer_employee_id' => $employee->managerId,
                    'status' => $first,
                ]);
                $review->placeForEmployee($employee);

                try {
                    DB::transaction(static fn () => $review->save());
                    $created++;
                } catch (UniqueConstraintViolationException) {
                    // Already created by an earlier, interrupted launch.
                }
            }

            if ($created === 0 && ! Review::query()->where('cycle_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['status' => __('No employee is in scope for this cycle.')]);
            }

            $locked->status = CycleStatus::Active;
            $locked->launched_at = now();
            $locked->save();
            $this->audit->record('talent.review_cycle.launched', $locked, ['reviews' => $created], $actor?->user);
            ReviewCycleLaunched::dispatch(['cycle_id' => $locked->id, 'reviews_created' => $created]);

            return $locked;
        });
    }

    public function startCalibration(ReviewCycle $cycle, ?TenantMembership $actor): ReviewCycle
    {
        return $this->advance($cycle, CycleStatus::Active, CycleStatus::Calibration, 'calibration_started_at', $actor);
    }

    public function close(ReviewCycle $cycle, ?TenantMembership $actor): ReviewCycle
    {
        return $this->advance($cycle, CycleStatus::Calibration, CycleStatus::Closed, 'closed_at', $actor);
    }

    private function advance(ReviewCycle $cycle, CycleStatus $from, CycleStatus $to, string $stamp, ?TenantMembership $actor): ReviewCycle
    {
        return DB::transaction(function () use ($cycle, $from, $to, $stamp, $actor): ReviewCycle {
            /** @var ReviewCycle $locked */
            $locked = ReviewCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw ValidationException::withMessages(['status' => __('That change is not allowed from the current status.')]);
            }

            $locked->status = $to;
            $locked->setAttribute($stamp, now());
            $locked->save();
            $this->audit->record('talent.review_cycle.'.$to->value, $locked, [], $actor?->user);

            return $locked;
        });
    }
}
