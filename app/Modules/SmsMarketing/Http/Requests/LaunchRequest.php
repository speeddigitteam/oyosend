<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Start a campaign now, or at a workspace-local time. */
final class LaunchRequest extends FormRequest
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
            'scheduled_for' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }
}
