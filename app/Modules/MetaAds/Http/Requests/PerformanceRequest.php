<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Range, currency and ad account filters of the performance dashboard and its export. */
final class PerformanceRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'account' => ['nullable', 'string', 'size:26'],
            'kind' => ['nullable', 'in:campaigns,daily'],
        ];
    }
}
