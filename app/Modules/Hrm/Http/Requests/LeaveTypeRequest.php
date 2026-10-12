<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use App\Modules\Hrm\Enums\LeaveApprovalFlow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'is_paid' => ['required', 'boolean'],
            'tracks_balance' => ['required', 'boolean'],
            'allows_half_day' => ['required', 'boolean'],
            'requires_reason' => ['required', 'boolean'],
            'max_consecutive_days' => ['nullable', 'integer', 'between:1,366'],
            'min_notice_days' => ['required', 'integer', 'between:0,365'],
            'approval_flow' => ['required', Rule::enum(LeaveApprovalFlow::class)],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,10000'],
        ];
    }
}
