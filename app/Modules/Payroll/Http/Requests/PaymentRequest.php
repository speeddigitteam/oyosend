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
final class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['paid', 'failed'])],
            'method' => ['nullable', Rule::enum(PaymentMethod::class), 'required_if:status,paid'],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'required_if:status,paid'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:90'],
        ];
    }
}
