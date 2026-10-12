<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\StageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a pipeline stage. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class StageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(StageType::class)],
            'position' => ['nullable', 'integer', 'between:0,60000'],
        ];
    }
}
