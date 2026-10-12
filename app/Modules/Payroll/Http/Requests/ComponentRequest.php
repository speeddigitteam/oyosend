<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\ComponentCalc;
use App\Modules\Payroll\Enums\ComponentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/D'],
            'kind' => ['required', Rule::enum(ComponentKind::class)],
            'calc' => ['required', Rule::enum(ComponentCalc::class)],
            'amount' => ['nullable', 'integer', 'min:0', 'required_if:calc,fixed'],
            'rate_bps' => ['nullable', 'integer', 'min:0', 'max:100000', 'required_if:calc,percent_basic'],
            'is_taxable' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
