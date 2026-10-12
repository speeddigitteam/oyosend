<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Render a template draft with sample values. */
final class TemplatePreviewRequest extends FormRequest
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
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
