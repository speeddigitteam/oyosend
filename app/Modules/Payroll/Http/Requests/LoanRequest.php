<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\LoanKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class LoanRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(LoanKind::class)],
            'title' => ['required', 'string', 'max:160'],
            'principal_amount' => ['required', 'integer', 'min:1'],
            'installment_count' => ['required', 'integer', 'between:1,60'],
            'first_due_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
