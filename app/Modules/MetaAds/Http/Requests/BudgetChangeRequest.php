<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirm a budget edit. `amount` is the new budget in the account currency as the person typed it;
 * `expected` is the current budget in minor units the person saw.
 */
final class BudgetChangeRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and the change manager. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:24'],
            'expected' => ['required', 'string', 'regex:/^\d{1,18}$/'],
            'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
        ];
    }
}
