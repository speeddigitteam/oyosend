<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Acknowledge a calibrated review. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class AcknowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
