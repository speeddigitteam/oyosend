<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Workspace AI settings. Input shape only: scopes not offered by a registered tool are dropped by the
 * settings action, and authorization is the route permission.
 */
final class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'chat_enabled' => ['required', 'boolean'],
            'drafting_enabled' => ['required', 'boolean'],
            'automation_enabled' => ['required', 'boolean'],
            'data_scopes' => ['present', 'array', 'max:20'],
            'data_scopes.*' => ['string', 'max:40'],
            'retention_days' => [
                'required', 'integer',
                'between:'.(int) config('ai.retention.min_days', 1).','.(int) config('ai.retention.max_days', 365),
            ],
            'redact_pii' => ['required', 'boolean'],
        ];
    }
}
