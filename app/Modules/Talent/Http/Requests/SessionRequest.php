<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\SessionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a training session (local date and time). Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class SessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:15,10080'],
            'location' => ['nullable', 'string', 'max:255'],
            'trainer' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'between:1,10000'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
        ];
    }
}
