<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A message template. Variables and length are checked by the manager. */
final class TemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', Rule::enum(TemplatePurpose::class)],
            'body' => ['required', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
