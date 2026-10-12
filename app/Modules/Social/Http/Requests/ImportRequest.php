<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A CSV file of posts. The importer verifies encoding, columns and every row. */
final class ImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:1024', 'extensions:csv,txt'],
            'mode' => ['required', Rule::in(['draft', 'schedule'])],
        ];
    }
}
