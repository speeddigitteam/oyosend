<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class SalaryRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'required', 'string', 'size:26'],
            'structure_id' => ['required', 'string', 'size:26'],
            'basic_amount' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
