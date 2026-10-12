<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\ConsentBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a candidate. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class CandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'headline' => ['nullable', 'string', 'max:160'],
            'current_company' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'source' => ['nullable', 'string', 'max:60'],
            'expected_amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'consent_basis' => ['nullable', Rule::enum(ConsentBasis::class)],
            'retain_until' => ['nullable', 'date_format:Y-m-d'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }
}
