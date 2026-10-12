<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Move a queued post to another workspace-local time. */
final class RescheduleRequest extends FormRequest
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
            'scheduled_for' => ['required', 'date_format:Y-m-d\TH:i'],
        ];
    }
}
