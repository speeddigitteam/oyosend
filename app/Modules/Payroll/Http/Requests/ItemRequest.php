<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ItemRequest extends FormRequest
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
            'period_id' => ['sometimes', 'required', 'string', 'size:26'],
            'type' => ['required', Rule::enum(ItemType::class)],
            'name' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'basic_bps' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_taxable' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
