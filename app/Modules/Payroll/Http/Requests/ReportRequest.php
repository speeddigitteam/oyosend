<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report' => ['nullable', Rule::in(['register', 'departments', 'deductions', 'employer'])],
            'period_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
