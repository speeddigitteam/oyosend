<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Requests;

use App\Modules\Ai\Actions\DraftService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An AI drafting request: summarise a record, draft a reply or rewrite a text. Input shape only; the
 * record is loaded through the tool layer as the signed-in member.
 */
final class DraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = (int) config('ai.limits.max_input_chars', 6000);

        return [
            'kind' => ['required', 'string', Rule::in([DraftService::SUMMARIZE, DraftService::REPLY, DraftService::REWRITE])],
            'record_type' => ['required_unless:kind,'.DraftService::REWRITE, 'nullable', 'string', Rule::in(DraftService::recordTypes())],
            'record_id' => ['required_unless:kind,'.DraftService::REWRITE, 'nullable', 'string', 'size:26'],
            'instruction' => ['nullable', 'string', 'max:1000'],
            'tone' => ['nullable', 'string', Rule::in(['neutral', 'friendly', 'formal'])],
            'text' => ['required_if:kind,'.DraftService::REWRITE, 'nullable', 'string', 'max:'.$max],
        ];
    }
}
