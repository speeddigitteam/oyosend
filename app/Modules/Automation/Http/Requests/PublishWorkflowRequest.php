<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Publish the draft with an optional change note.
 * Input shape only: authorization is the route's `can:` permission, and every id is resolved through
 * tenant-scoped models.
 */
final class PublishWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:255']];
    }
}
