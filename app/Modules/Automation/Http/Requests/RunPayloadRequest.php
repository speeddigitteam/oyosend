<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Start a manual run or a test run with a JSON object as the trigger payload.
 * Input shape only: authorization is the route's `can:` permission, and every id is resolved through
 * tenant-scoped models.
 */
final class RunPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');

        if (is_string($payload)) {
            $decoded = trim($payload) === '' ? [] : json_decode($payload, true);

            $this->merge(['payload' => is_array($decoded) ? $decoded : $payload]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['payload' => ['nullable', 'array']];
    }
}
