<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class AgentRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'membership_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'size:26'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'max_open_tickets' => ['nullable', 'integer', 'between:1,500'],
        ];
    }
}
