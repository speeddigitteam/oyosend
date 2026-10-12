<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\AssigneeRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a task to a checklist. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assignee_role' => ['required', Rule::enum(AssigneeRole::class)],
            'assignee_employee_id' => ['nullable', 'string', 'size:26'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
