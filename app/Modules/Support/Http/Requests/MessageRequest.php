<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Actions\AttachmentStore;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketStatus;
use Illuminate\Validation\Rule;

final class MessageRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(MessageKind::class)],
            'body' => ['required', 'string', 'max:20000'],
            // The status to move the ticket to together with this message (reply and resolve in one step).
            'status' => ['nullable', Rule::in([
                TicketStatus::Open->value, TicketStatus::WaitingCustomer->value, TicketStatus::OnHold->value,
                TicketStatus::Resolved->value, TicketStatus::Closed->value,
            ])],
            'attachments' => ['nullable', 'array', 'max:'.AttachmentStore::MAX_FILES],
            'attachments.*' => ['file', 'max:'.AttachmentStore::MAX_SIZE_KB, 'extensions:'.implode(',', AttachmentStore::EXTENSIONS)],
        ];
    }
}
