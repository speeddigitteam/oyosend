<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use App\Modules\Communication\Actions\TemplateManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or change a message template. */
final class TemplateRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'locale' => ['required', 'string', Rule::in(TemplateManager::LOCALES)],
            'purpose' => ['required', Rule::enum(MessagePurpose::class)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
