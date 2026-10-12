<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Talent settings. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'default_probation_days' => ['required', 'integer', 'between:1,730'],
            'probation_reminder_days' => ['required', 'integer', 'between:1,180'],
            'certification_reminder_days' => ['required', 'integer', 'between:1,365'],
            'candidate_retention_months' => ['required', 'integer', 'between:1,120'],
        ];
    }
}
