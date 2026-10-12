<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use App\Modules\Hrm\Enums\LeaveSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class LeaveSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'string', 'size:26'],
            'leave_type_id' => ['required', 'string', 'size:26'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_session' => ['nullable', Rule::enum(LeaveSession::class)],
            'end_session' => ['nullable', Rule::enum(LeaveSession::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
