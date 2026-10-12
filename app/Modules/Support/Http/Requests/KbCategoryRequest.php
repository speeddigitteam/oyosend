<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class KbCategoryRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'integer', 'between:0,1000'],
        ];
    }
}
