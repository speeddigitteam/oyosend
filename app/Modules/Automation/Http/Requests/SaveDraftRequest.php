<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Save (or only validate) a workflow graph. The graph itself is checked by the validator.
 * Input shape only: authorization is the route's `can:` permission, and every id is resolved through
 * tenant-scoped models.
 */
final class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'graph' => ['required', 'array'],
            'graph.nodes' => ['required', 'array', 'max:200'],
            'graph.edges' => ['present', 'array', 'max:500'],
        ];
    }
}
