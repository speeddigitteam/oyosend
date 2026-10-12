<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A free-text note. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class NoteOnlyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
