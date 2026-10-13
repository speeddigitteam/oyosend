<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Input of one AI suggestion for the post composer. The text is the person's own draft; it is bounded here and
 * again by the TextAssistant contract. Nothing here is a permission, tenant or entitlement.
 */
final class AiAssistRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and the controller (create or edit as well). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:5000'],
            'link_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'tone' => ['nullable', Rule::in(PostAiAssist::TONES)],
            'language' => ['nullable', Rule::in(PostAiAssist::LANGUAGES)],
            'count' => ['nullable', 'integer', 'between:1,15'],
            'attempt' => ['nullable', 'integer', 'between:1,50'],
            'account_ids' => ['nullable', 'array', 'max:20'],
            'account_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $operation = (string) $this->route('operation');
                $has = fn (string $key): bool => trim((string) $this->input($key, '')) !== '';

                $ok = match ($operation) {
                    PostAiAssist::OP_CAPTION, PostAiAssist::OP_HASHTAGS => $has('notes') || $has('body') || $has('title'),
                    default => $has('body'),
                };

                if (! $ok) {
                    $validator->errors()->add('body', __('Write some text first, then ask for a suggestion.'));
                }

                if ($operation === PostAiAssist::OP_TRANSLATE && ! in_array($this->input('language'), ['en', 'bn'], true)) {
                    $validator->errors()->add('language', __('Choose English or Bengali to translate into.'));
                }
            },
        ];
    }
}
