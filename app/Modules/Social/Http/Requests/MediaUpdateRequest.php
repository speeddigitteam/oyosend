<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Rename a file or describe it for accessibility. */
final class MediaUpdateRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and, for one record, by RecordAccess. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:190'],
            'alt_text' => ['nullable', 'string', 'max:500'],
        ];
    }
}
