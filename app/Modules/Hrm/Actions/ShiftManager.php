<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shift definitions and who works which shift from when. */
final class ShiftManager
{
    /** @param array<string, mixed> $attributes */
    public function save(?Shift $shift, array $attributes): Shift
    {
        $start = (string) ($attributes['starts_at'] ?? $shift?->starts_at);
        $end = (string) ($attributes['ends_at'] ?? $shift?->ends_at);

        if ($start === $end) {
            throw ValidationException::withMessages(['ends_at' => __('A shift cannot start and end at the same time.')]);
        }

        return DB::transaction(function () use ($shift, $attributes): Shift {
            $name = (string) ($attributes['name'] ?? $shift?->name);
            $duplicate = Shift::query()->where('name', $name)->when($shift !== null, fn ($query) => $query->whereKeyNot($shift?->id))->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => __('A shift with this name already exists.')]);
            }

            $shift ??= new Shift;
            $shift->fill($attributes);
            $shift->save();

            if ($shift->is_default) {
                Shift::query()->whereKeyNot($shift->id)->where('is_default', true)->update(['is_default' => false]);
            }

            return $shift;
        });
    }

    public function assign(Employee $employee, Shift $shift, string $startsOn, ?string $endsOn, ?TenantMembership $actor = null): ShiftAssignment
    {
        if (! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => __('The selected shift is not available.')]);
        }

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('The end date cannot be before the start date.')]);
        }

        return DB::transaction(function () use ($employee, $shift, $startsOn, $endsOn, $actor): ShiftAssignment {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $overlapping = ShiftAssignment::query()
                ->where('employee_id', $employee->id)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
                ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn))
                ->get();

            foreach ($overlapping as $existing) {
                $startsBefore = $existing->starts_on->toDateString() < $startsOn;
                $extendsPast = $endsOn !== null && ($existing->ends_on === null || $existing->ends_on->toDateString() > $endsOn);

                // A later assignment shortens the earlier one to the day before it starts.
                // Starting on or before it, or splitting it in two, would leave two shifts on one day.
                if (! $startsBefore || $extendsPast) {
                    throw ValidationException::withMessages(['starts_on' => __('This overlaps another shift assignment.')]);
                }

                $existing->update(['ends_on' => CarbonImmutable::parse($startsOn, 'UTC')->subDay()->toDateString()]);
            }

            $assignment = (new ShiftAssignment([
                'shift_id' => $shift->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'assigned_by' => $actor?->id,
            ]))->placeFor($employee);
            $assignment->save();

            return $assignment;
        });
    }
}
