<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use Illuminate\Validation\Rule;

final class RuleRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'integer', 'between:0,1000'],
            'is_active' => ['sometimes', 'boolean'],
            'match_channel' => ['nullable', Rule::enum(TicketChannel::class)],
            'match_priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'match_category_id' => ['nullable', 'string', 'size:26'],
            'match_company_id' => ['nullable', 'string', 'size:26'],
            'match_keyword' => ['nullable', 'string', 'max:80'],
            'queue_id' => ['required', 'string', 'size:26'],
            'assign_agent_id' => ['nullable', 'string', 'size:26'],
            'set_priority' => ['nullable', Rule::enum(TicketPriority::class)],
        ];
    }
}
