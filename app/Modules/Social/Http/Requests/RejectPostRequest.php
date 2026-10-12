<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Send a post back with a reason. */
final class RejectPostRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
