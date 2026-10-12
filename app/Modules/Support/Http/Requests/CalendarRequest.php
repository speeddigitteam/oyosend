<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

final class CalendarRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['nullable', 'string', 'max:64'],
            // ISO weekday (1 = Monday) => list of [start, end] "HH:MM" pairs.
            'hours' => ['required', 'array'],
            'hours.*' => ['array', 'max:6'],
            'hours.*.*' => ['array', 'size:2'],
            'hours.*.*.*' => ['string', 'max:5'],
            'holidays' => ['nullable', 'array', 'max:400'],
            'holidays.*' => ['date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
