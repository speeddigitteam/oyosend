<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Queue a post, now or at a workspace-local time. */
final class ScheduleRequest extends FormRequest
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
            'publish_now' => ['nullable', 'boolean'],
            'scheduled_for' => ['required_without:publish_now', 'nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }
}
