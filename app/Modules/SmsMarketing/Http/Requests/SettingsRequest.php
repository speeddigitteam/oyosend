<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Workspace SMS Marketing settings. The bounds come from `config/sms_marketing.php`. */
final class SettingsRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and, for one record, by RecordAccess. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        [$capMin, $capMax] = (array) config('sms_marketing.bounds.daily_cap', [1, 100000]);
        [$rateMin, $rateMax] = (array) config('sms_marketing.bounds.throttle_per_minute', [1, 600]);

        return [
            'daily_cap' => ['required', 'integer', 'between:'.$capMin.','.$capMax],
            'throttle_per_minute' => ['required', 'integer', 'between:'.$rateMin.','.$rateMax],
            'opt_out_wording' => ['nullable', 'string', 'max:60'],
            'segment_cost_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'default_sender_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
