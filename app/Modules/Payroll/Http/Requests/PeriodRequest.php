<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\PeriodKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class PeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PeriodKind::class)],
            'name' => ['nullable', 'string', 'max:120'],
            'month' => ['nullable', 'required_if:kind,monthly', 'date_format:Y-m'],
            'starts_on' => ['nullable', 'required_if:kind,custom', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_if:kind,custom', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'pay_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
