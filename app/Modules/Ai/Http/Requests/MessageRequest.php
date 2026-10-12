<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A chat message. Input shape only: the conversation is resolved from the member own conversations.
 */
final class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:'.(int) config('ai.limits.max_input_chars', 6000)],
        ];
    }
}
