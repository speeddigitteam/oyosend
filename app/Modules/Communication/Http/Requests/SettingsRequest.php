<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Sender identity, quiet hours and the marketing footer. */
final class SettingsRequest extends FormRequest
{
    /** The route middleware (`can:`) authorizes; this request only validates. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to_email' => ['nullable', 'string', 'email', 'max:190'],
            'quiet_hours_enabled' => ['required', 'boolean'],
            'quiet_hours_start' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'quiet_hours_end' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
