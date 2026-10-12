<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\AssigneeRole;
use App\Modules\Talent\Enums\ChecklistKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a checklist template with its tasks. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class TemplateRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(ChecklistKind::class)],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'tasks' => ['nullable', 'array', 'max:60'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.assignee_role' => ['required', Rule::enum(AssigneeRole::class)],
            'tasks.*.assignee_employee_id' => ['nullable', 'string', 'size:26'],
            'tasks.*.due_offset_days' => ['nullable', 'integer', 'between:-365,365'],
        ];
    }
}
