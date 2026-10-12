<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename a workflow or change its description.
 * Input shape only: authorization is the route's `can:` permission, and every id is resolved through
 * tenant-scoped models.
 */
final class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
