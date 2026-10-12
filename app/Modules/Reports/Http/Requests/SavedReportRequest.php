<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Actions\ReportRunner;
use App\Modules\Reports\Enums\RangeMode;
use App\Modules\Reports\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation only. Whether the provider exists, may be run by this person and accepts these
 * columns, filters and dates is decided by `SavedReportManager` against the provider's own
 * declaration; authorization is the route's `can:` plus the controller's record check.
 */
final class SavedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'provider_key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],
            'columns' => ['nullable', 'array', 'max:40'],
            'columns.*' => ['string', 'max:40'],
            'filters' => ['nullable', 'array', 'max:20'],
            'range_mode' => ['required', Rule::enum(RangeMode::class)],
            'range_days' => ['required_if:range_mode,last_days', 'nullable', 'integer', 'min:1', 'max:'.ReportRunner::MAX_DAYS],
            'range_from' => ['required_if:range_mode,fixed', 'nullable', 'date_format:Y-m-d'],
            'range_to' => ['required_if:range_mode,fixed', 'nullable', 'date_format:Y-m-d', 'after_or_equal:range_from'],
            'visibility' => ['required', Rule::enum(Visibility::class)],
            'role_id' => ['required_if:visibility,role', 'nullable', 'string', 'size:26'],
            'department_id' => ['required_if:visibility,department', 'nullable', 'string', 'size:26'],
        ];
    }
}
