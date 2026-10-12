<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Actions\AttachmentStore;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use Illuminate\Validation\Rule;

final class TicketStoreRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'channel' => ['nullable', Rule::in([TicketChannel::Web->value, TicketChannel::Phone->value, TicketChannel::Email->value, TicketChannel::Chat->value])],
            'category_id' => ['nullable', 'string', 'size:26'],
            'queue_id' => ['nullable', 'string', 'size:26'],
            'contact_id' => ['nullable', 'string', 'size:26'],
            'company_id' => ['nullable', 'string', 'size:26'],
            'requester_name' => ['nullable', 'string', 'max:160'],
            'requester_email' => ['nullable', 'email', 'max:190'],
            'assignee_id' => ['nullable', 'string', 'size:26'],
            'tags' => ['nullable', 'array', 'max:'.TicketManager::MAX_TAGS],
            'tags.*' => ['string', 'max:60'],
            'attachments' => ['nullable', 'array', 'max:'.AttachmentStore::MAX_FILES],
            'attachments.*' => ['file', 'max:'.AttachmentStore::MAX_SIZE_KB, 'extensions:'.implode(',', AttachmentStore::EXTENSIONS)],
        ];
    }
}
