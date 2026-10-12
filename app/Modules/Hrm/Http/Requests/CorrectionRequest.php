<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class CorrectionRequest extends FormRequest
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
            'work_date' => ['required', 'date_format:Y-m-d'],
            'clock_in_at' => ['required', 'date_format:Y-m-d H:i'],
            'clock_out_at' => ['required', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
