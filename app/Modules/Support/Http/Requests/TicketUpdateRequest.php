<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\TicketPriority;
use Illuminate\Validation\Rule;

final class TicketUpdateRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:200'],
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'queue_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'contact_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'company_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'requester_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'requester_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.TicketManager::MAX_TAGS],
            'tags.*' => ['string', 'max:60'],
        ];
    }
}
