<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve or reject an approval task.
 * Input shape only: authorization is the route's `can:` permission, and every id is resolved through
 * tenant-scoped models.
 */
final class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
