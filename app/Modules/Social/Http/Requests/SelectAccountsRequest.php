<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The profiles to connect from a platform grant. */
final class SelectAccountsRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and, for one record, by RecordAccess. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_ids' => ['required', 'array', 'min:1', 'max:50'],
            'external_ids.*' => ['string', 'max:191'],
        ];
    }
}
