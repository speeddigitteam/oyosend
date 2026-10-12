<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A campaign draft. Group, template and sender references are re-checked against the workspace by the manager. */
final class CampaignRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'template_id' => ['nullable', 'string', 'size:26'],
            'body' => ['nullable', 'string', 'max:2000'],
            'sender_identity_id' => ['nullable', 'string', 'size:26'],
            'group_ids' => ['required', 'array', 'min:1', 'max:20'],
            'group_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
