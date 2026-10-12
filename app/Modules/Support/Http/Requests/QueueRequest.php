<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\AssignmentMode;
use Illuminate\Validation\Rule;

final class QueueRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'sla_policy_id' => ['nullable', 'string', 'size:26'],
            'assignment_mode' => ['required', Rule::enum(AssignmentMode::class)],
            'is_active' => ['sometimes', 'boolean'],
            'agent_ids' => ['sometimes', 'array', 'max:200'],
            'agent_ids.*' => ['string', 'size:26'],
        ];
    }
}
