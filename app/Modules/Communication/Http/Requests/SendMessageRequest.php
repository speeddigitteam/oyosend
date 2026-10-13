<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Composer: one message to one contact. */
final class SendMessageRequest extends FormRequest
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
            'contact_id' => ['required', 'string', 'size:26'],
            'channel' => ['required', Rule::enum(Channel::class)],
            // Optional and advisory: the server derives the purpose (see ManualMessages).
            'purpose' => ['nullable', Rule::enum(MessagePurpose::class)],
            'template_id' => ['nullable', 'string', 'size:26'],
            'subject' => ['nullable', 'string', 'max:200', Rule::requiredIf(fn (): bool => ! $this->filled('template_id') && $this->input('channel') === Channel::Email->value)],
            'body' => ['nullable', 'string', 'max:5000', 'required_without:template_id'],
            'request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
