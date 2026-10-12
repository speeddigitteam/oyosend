<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\LeaveAccrual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class LeavePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:100'],
            'employment_types' => ['nullable', 'array'],
            'employment_types.*' => ['string', Rule::enum(EmploymentType::class)],
            'accrual' => ['required', Rule::enum(LeaveAccrual::class)],
            'annual_units' => ['required', 'integer', 'between:0,732'],
            'carry_forward_max_units' => ['required', 'integer', 'between:0,732'],
            'max_balance_units' => ['nullable', 'integer', 'between:0,1464'],
            'max_units_per_request' => ['nullable', 'integer', 'between:1,732'],
            'sandwich_rule' => ['required', 'boolean'],
            'min_service_days' => ['required', 'integer', 'between:0,3650'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
