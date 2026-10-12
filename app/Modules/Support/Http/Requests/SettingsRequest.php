<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketPriority;
use Illuminate\Validation\Rule;

final class SettingsRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ticket_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_#-]*$/'],
            'number_padding' => ['required', 'integer', 'between:1,10'],
            'default_priority' => ['required', Rule::enum(TicketPriority::class)],
            'default_queue_id' => ['nullable', 'string', 'size:26'],
            'default_sla_policy_id' => ['nullable', 'string', 'size:26'],
            'csat_enabled' => ['required', 'boolean'],
            'csat_expiry_days' => ['required', 'integer', 'between:1,90'],
            'csat_send_email' => ['required', 'boolean'],
            'auto_close_days' => ['nullable', 'integer', 'between:1,365'],
        ];
    }
}
