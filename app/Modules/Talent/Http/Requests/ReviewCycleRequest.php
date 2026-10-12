<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or edit a review cycle. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ReviewCycleRequest extends FormRequest
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
            'form_id' => ['required', 'string', 'size:26'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'self_due_on' => ['nullable', 'date_format:Y-m-d'],
            'manager_due_on' => ['nullable', 'date_format:Y-m-d'],
            'requires_self_review' => ['nullable', 'boolean'],
            'department_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
