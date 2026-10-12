<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class MergeRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_number' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
