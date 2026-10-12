<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\TerminationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(EmployeeStatus::class)],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'termination_type' => ['nullable', Rule::enum(TerminationType::class)],
            'last_working_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
