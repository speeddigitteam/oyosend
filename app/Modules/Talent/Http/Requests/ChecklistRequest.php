<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Requests;

use App\Modules\Talent\Enums\ChecklistKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start an onboarding or offboarding checklist. Input shape only: authorization is the route's `can:` permission plus the record
 * scope the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class ChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'size:26'],
            'kind' => ['required', Rule::enum(ChecklistKind::class)],
            'template_id' => ['nullable', 'string', 'size:26'],
            'anchor_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
