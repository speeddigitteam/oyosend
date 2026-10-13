<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Meta Ads settings: the management switch, the history window and per-ad-account budget ceilings (decimal text). */
final class SettingsRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'management_enabled' => ['required', 'boolean'],
            'lookback_days' => ['required', 'integer', 'min:'.(int) config('meta_ads.sync.min_lookback_days', 7), 'max:'.(int) config('meta_ads.sync.max_lookback_days', 90)],
            'ceilings' => ['nullable', 'array', 'max:50'],
            'ceilings.*.account_id' => ['required', 'string', 'size:26'],
            'ceilings.*.daily' => ['nullable', 'string', 'max:24'],
            'ceilings.*.lifetime' => ['nullable', 'string', 'max:24'],
        ];
    }
}
