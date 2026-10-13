<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Level, status, search and range filters of the campaigns list. */
final class CampaignListRequest extends FormRequest
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
            'level' => ['nullable', 'in:campaign,adset,ad'],
            'status' => ['nullable', 'in:all,active,paused,other'],
            'q' => ['nullable', 'string', 'max:100'],
            'account' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
