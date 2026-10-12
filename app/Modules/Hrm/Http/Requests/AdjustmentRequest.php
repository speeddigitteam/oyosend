<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class AdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'size:26'],
            'leave_type_id' => ['required', 'string', 'size:26'],
            'units' => ['required', 'integer', 'between:-732,732', 'not_in:0'],
            'leave_year' => ['nullable', 'integer', 'between:2000,2100'],
            'note' => ['required', 'string', 'max:300'],
        ];
    }
}
