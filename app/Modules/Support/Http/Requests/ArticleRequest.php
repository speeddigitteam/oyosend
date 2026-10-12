<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class ArticleRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:100000'],
            'category_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
