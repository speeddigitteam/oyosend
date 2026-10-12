<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'work_email' => ['nullable', 'email:rfc', 'max:190'],
            'personal_email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'national_id' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'designation_id' => ['nullable', 'string', 'size:26'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'manager_id' => ['nullable', 'string', 'size:26'],
            'membership_id' => ['nullable', 'string', 'size:26'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'joined_on' => [$creating ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'status' => [$creating ? 'sometimes' : 'prohibited', Rule::in([EmployeeStatus::Probation->value, EmployeeStatus::Active->value])],
            'probation_ends_on' => ['nullable', 'date_format:Y-m-d'],
            'cost_rate_amount' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'cost_currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
