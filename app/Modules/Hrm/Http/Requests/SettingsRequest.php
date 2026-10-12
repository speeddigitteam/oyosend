<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_\/]*$/'],
            'employee_padding' => ['required', 'integer', 'between:1,10'],
            'weekly_off_days' => ['present', 'array', 'max:6'],
            'weekly_off_days.*' => ['integer', 'between:1,7', 'distinct'],
            'leave_year_start_month' => ['required', 'integer', 'between:1,12'],
            'work_day_minutes' => ['required', 'integer', 'between:60,1440'],
        ];
    }
}
