<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Confirm pausing or resuming one item: `expected` is the status the person saw. */
final class StatusChangeRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and the change manager. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:pause,resume'],
            'expected' => ['required', 'in:ACTIVE,PAUSED'],
            'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
        ];
    }
}
