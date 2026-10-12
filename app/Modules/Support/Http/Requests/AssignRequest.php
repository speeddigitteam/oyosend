<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class AssignRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A membership id of an active agent; null unassigns.
            'assignee_id' => ['nullable', 'string', 'size:26'],
            // Hand the ticket to the next round-robin agent of its queue (or of `queue_id`).
            'auto' => ['sometimes', 'boolean'],
            'queue_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
