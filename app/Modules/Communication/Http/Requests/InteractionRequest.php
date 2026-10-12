<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use App\Modules\Communication\Enums\InteractionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Log a note, call, meeting or email on a timeline by hand. */
final class InteractionRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_map(static fn (InteractionType $type): string => $type->value, InteractionType::manual()))],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'summary' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
