<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ShiftRequest extends FormRequest
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
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'break_minutes' => ['required', 'integer', 'between:0,480'],
            'late_grace_minutes' => ['required', 'integer', 'between:0,240'],
            'early_grace_minutes' => ['required', 'integer', 'between:0,240'],
            'overtime_threshold_minutes' => ['required', 'integer', 'between:0,480'],
            'half_day_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'weekly_off_days' => ['nullable', 'array', 'max:6'],
            'weekly_off_days.*' => ['integer', 'between:1,7', 'distinct'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
