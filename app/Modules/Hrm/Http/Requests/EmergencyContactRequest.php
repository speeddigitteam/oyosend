<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class EmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'relationship' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'address' => ['nullable', 'string', 'max:300'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
