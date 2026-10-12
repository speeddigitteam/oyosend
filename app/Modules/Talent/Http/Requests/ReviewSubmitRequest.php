<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submit the self or manager step of a review. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ReviewSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ratings' => ['required', 'array', 'min:1', 'max:30'],
            'ratings.*.rating' => ['required', 'integer', 'between:1,10'],
            'ratings.*.comment' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
