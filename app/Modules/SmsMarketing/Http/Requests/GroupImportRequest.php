<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A CSV file with a `phone` column. The importer verifies encoding, size and every row. */
final class GroupImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:512', 'extensions:csv,txt'],
        ];
    }
}
