<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a job opening. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class OpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'hiring_manager_id' => ['nullable', 'string', 'size:26'],
            'pipeline_id' => ['nullable', 'string', 'size:26'],
            'employment_type' => ['nullable', 'string', Rule::in(['full_time', 'part_time', 'contract', 'intern', 'consultant'])],
            'location' => ['nullable', 'string', 'max:160'],
            'headcount' => ['required', 'integer', 'between:1,500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'opens_on' => ['nullable', 'date_format:Y-m-d'],
            'closes_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:opens_on'],
            'status' => ['nullable', Rule::in(['draft', 'open'])],
        ];
    }
}
