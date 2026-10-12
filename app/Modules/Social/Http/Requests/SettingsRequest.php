<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Workspace social settings. */
final class SettingsRequest extends FormRequest
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
            'require_approval' => ['required', 'boolean'],
            'account_hourly_limit' => ['required', 'integer', 'between:1,100'],
        ];
    }
}
