<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\RuleBasis;
use App\Modules\Payroll\Enums\RuleCategory;
use App\Modules\Payroll\Enums\RuleMethod;
use App\Modules\Payroll\Enums\RulePayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class RuleSetRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/D'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'engine' => ['required', 'string', 'max:30'],
            'category' => ['required', Rule::enum(RuleCategory::class)],
            'payer' => ['required', Rule::enum(RulePayer::class)],
            'basis' => ['required', Rule::enum(RuleBasis::class)],
            'method' => ['required', Rule::enum(RuleMethod::class)],
            'annualize' => ['required', 'boolean'],
            'periods_per_year' => ['required', 'integer', 'between:1,366'],
            'min_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'max_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'employment_types' => ['nullable', 'array', 'max:10'],
            'employment_types.*' => ['string', 'max:20'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'slabs' => ['required', 'array', 'min:1', 'max:50'],
            'slabs.*.from' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'slabs.*.to' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'slabs.*.rate_bps' => ['required', 'integer', 'between:0,10000'],
            'slabs.*.fixed' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
