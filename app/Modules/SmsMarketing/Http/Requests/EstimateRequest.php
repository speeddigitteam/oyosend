<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** What a draft would reach and cost: the text and the chosen groups. Nothing is saved. */
final class EstimateRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and, for one record, by RecordAccess. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
            'group_ids' => ['nullable', 'array', 'max:20'],
            'group_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
