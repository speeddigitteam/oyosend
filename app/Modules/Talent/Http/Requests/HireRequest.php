<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hire the candidate of an application. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class HireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
