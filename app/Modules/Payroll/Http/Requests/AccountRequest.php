<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'account_holder' => ['nullable', 'string', 'max:160'],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'bank_branch' => ['nullable', 'string', 'max:160'],
            'routing_number' => ['nullable', 'string', 'max:40'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'clear_account_number' => ['nullable', 'boolean'],
        ];
    }
}
