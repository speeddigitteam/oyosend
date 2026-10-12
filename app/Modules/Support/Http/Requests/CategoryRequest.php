<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class CategoryRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'queue_id' => ['nullable', 'string', 'size:26'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
