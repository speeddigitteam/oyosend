<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The workspace's HR reference data: designations, holidays, leave types and leave policies. */
final class HrmCatalogue
{
    /** @param array<string, mixed> $attributes */
    public function saveDesignation(?Designation $designation, array $attributes): Designation
    {
        $name = (string) ($attributes['name'] ?? $designation?->name);
        $this->ensureUnique(Designation::query(), 'name', $name, $designation, __('A designation with this name already exists.'));

        if (($attributes['is_active'] ?? true) === false && $designation !== null
            && Employee::query()->where('designation_id', $designation->id)->where('status', '!=', EmployeeStatus::Terminated)->exists()) {
            throw ValidationException::withMessages(['is_active' => __('Employees still hold this designation.')]);
        }

        $designation ??= new Designation;
        $designation->fill($attributes)->save();

        return $designation;
    }

    /** @param array<string, mixed> $attributes */
    public function saveHoliday(?Holiday $holiday, array $attributes): Holiday
    {
        $date = (string) ($attributes['holiday_on'] ?? $holiday?->holiday_on->toDateString());
        $taken = Holiday::query()->where('holiday_on', $date)->when($holiday !== null, fn ($query) => $query->whereKeyNot($holiday?->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['holiday_on' => __('There is already a holiday on this date.')]);
        }

        $holiday ??= new Holiday;
        $holiday->fill($attributes)->save();

        return $holiday;
    }

    /** @param array<string, mixed> $attributes */
    public function saveLeaveType(?LeaveType $type, array $attributes): LeaveType
    {
        $this->ensureUnique(LeaveType::query(), 'name', (string) ($attributes['name'] ?? $type?->name), $type, __('A leave type with this name already exists.'));
        $this->ensureUnique(LeaveType::query(), 'code', (string) ($attributes['code'] ?? $type?->code), $type, __('A leave type with this code already exists.'), 'code');

        $type ??= new LeaveType;
        $type->fill($attributes)->save();

        return $type;
    }

    /** @param array<string, mixed> $attributes */
    public function saveLeavePolicy(?LeavePolicy $policy, array $attributes): LeavePolicy
    {
        return DB::transaction(function () use ($policy, $attributes): LeavePolicy {
            $typeId = (string) ($attributes['leave_type_id'] ?? $policy?->leave_type_id);

            if (LeaveType::query()->whereKey($typeId)->doesntExist()) {
                throw ValidationException::withMessages(['leave_type_id' => __('The selected leave type is not available.')]);
            }

            $name = (string) ($attributes['name'] ?? $policy?->name);
            $duplicate = LeavePolicy::query()->where('leave_type_id', $typeId)->where('name', $name)
                ->when($policy !== null, fn ($query) => $query->whereKeyNot($policy?->id))->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => __('This leave type already has a policy with this name.')]);
            }

            $policy ??= new LeavePolicy;
            $policy->fill($attributes)->save();

            return $policy;
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function ensureUnique($query, string $column, string $value, ?Model $ignore, string $message, string $field = 'name'): void
    {
        $taken = $query->where($column, $value)->when($ignore !== null, fn ($builder) => $builder->whereKeyNot($ignore?->getKey()))->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
