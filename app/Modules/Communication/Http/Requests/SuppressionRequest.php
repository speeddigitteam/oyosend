<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\SuppressionScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add an address to the suppression list by hand. */
final class SuppressionRequest extends FormRequest
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
            'address' => ['required', 'string', 'max:190'],
            'scope' => ['required', Rule::enum(SuppressionScope::class)],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
