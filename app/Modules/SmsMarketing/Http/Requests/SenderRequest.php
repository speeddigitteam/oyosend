<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use App\Modules\SmsMarketing\Enums\RegistrationStatus;
use App\Modules\SmsMarketing\Enums\SenderIdType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A recorded sender id. The shape rules live in `SenderIdRules`; registration is the workspace's own note. */
final class SenderRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:80'],
            'value' => ['required', 'string', 'max:16'],
            'type' => ['required', Rule::enum(SenderIdType::class)],
            'registration_status' => ['required', Rule::enum(RegistrationStatus::class)],
            'registration_reference' => ['nullable', 'string', 'max:120'],
            'registration_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
