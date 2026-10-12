<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Contacts to add to a group. The controller narrows them to the contacts the member may see. */
final class GroupMembersRequest extends FormRequest
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
            'contact_ids' => ['required', 'array', 'min:1', 'max:200'],
            'contact_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
