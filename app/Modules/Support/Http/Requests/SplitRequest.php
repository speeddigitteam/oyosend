<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class SplitRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'message_ids' => ['required', 'array', 'min:1', 'max:200'],
            'message_ids.*' => ['string', 'size:26'],
        ];
    }
}
