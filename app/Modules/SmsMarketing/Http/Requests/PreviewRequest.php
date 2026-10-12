<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Render a text with sample values and count its segments. Nothing is saved. */
final class PreviewRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:2000'],
            'purpose' => ['required', Rule::enum(TemplatePurpose::class)],
        ];
    }
}
