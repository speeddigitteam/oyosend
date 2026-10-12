<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\GoalKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a goal or KPI. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class GoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'string', 'size:26'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'kind' => ['nullable', Rule::enum(GoalKind::class)],
            'unit' => ['nullable', 'string', 'max:40'],
            'start_value' => ['nullable', 'integer', 'between:-9000000000000000,9000000000000000'],
            'target_value' => ['required', 'integer', 'between:-9000000000000000,9000000000000000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'cycle_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
