<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Record a contact's marketing consent decision by hand. */
final class ConsentRequest extends FormRequest
{
    /** The route middleware (`can:`) authorizes; this request only validates. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(Channel::class)],
            'status' => ['required', Rule::enum(ConsentStatus::class)],
            'method' => ['required', 'string', Rule::in(array_values(array_diff(ConsentManager::METHODS, ['unsubscribe_link', 'complaint'])))],
            'evidence' => ['nullable', 'string', 'max:500', 'required_if:status,opted_in'],
        ];
    }
}
