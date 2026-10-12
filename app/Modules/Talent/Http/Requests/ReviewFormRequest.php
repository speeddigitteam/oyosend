<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or edit a review form with weighted competencies. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ReviewFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scale_max' => ['required', 'integer', 'between:3,10'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'min:1', 'max:30'],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.weight_bp' => ['required', 'integer', 'between:1,10000'],
        ];
    }
}
