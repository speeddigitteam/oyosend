<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Requests;

use App\Modules\Hrm\Enums\HolidayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route's `can:` permission plus the record scope
 * the controller applies; every id is re-resolved through tenant-scoped models.
 */
final class HolidayRequest extends FormRequest
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
            'holiday_on' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::enum(HolidayType::class)],
        ];
    }
}
