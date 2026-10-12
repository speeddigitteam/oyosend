<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Actions\SlaConfiguration;

final class PolicyRequest extends SupportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $minutes = ['nullable', 'integer', 'between:0,'.SlaConfiguration::MAX_MINUTES];

        return [
            'name' => ['required', 'string', 'max:120'],
            'calendar_id' => ['nullable', 'string', 'size:26'],
            'pause_on_waiting' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'targets' => ['required', 'array'],
            'targets.low.first_response' => $minutes,
            'targets.low.resolution' => $minutes,
            'targets.normal.first_response' => $minutes,
            'targets.normal.resolution' => $minutes,
            'targets.high.first_response' => $minutes,
            'targets.high.resolution' => $minutes,
            'targets.urgent.first_response' => $minutes,
            'targets.urgent.resolution' => $minutes,
        ];
    }
}
