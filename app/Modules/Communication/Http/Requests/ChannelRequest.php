<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use App\Modules\Communication\Channels\CredentialResolver;
use Illuminate\Foundation\Http\FormRequest;

/** A channel connection: provider, non-secret settings and credential REFERENCES (never secrets). */
final class ChannelRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:40'],
            'is_enabled' => ['required', 'boolean'],
            'settings' => ['nullable', 'array', 'max:20'],
            'credential_ref' => ['nullable', 'string', 'regex:'.CredentialResolver::PATTERN],
            'webhook_secret_ref' => ['nullable', 'string', 'regex:'.CredentialResolver::PATTERN],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
