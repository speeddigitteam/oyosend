<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketStatus;
use Illuminate\Validation\Rule;

final class StatusRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                TicketStatus::Open->value, TicketStatus::WaitingCustomer->value, TicketStatus::OnHold->value,
                TicketStatus::Resolved->value, TicketStatus::Closed->value,
            ])],
        ];
    }
}
