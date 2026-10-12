<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Complete an enrolment. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class CompleteEnrolmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'completed_on' => ['nullable', 'date_format:Y-m-d'],
            'score' => ['nullable', 'integer', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
