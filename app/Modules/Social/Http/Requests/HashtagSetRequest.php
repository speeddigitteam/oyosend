<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A named list of hashtags. `tags` is a list or free text separated by spaces, commas or semicolons. */
final class HashtagSetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'tags' => ['required'],
            'tags.*' => ['string', 'max:101'],
        ];
    }
}
