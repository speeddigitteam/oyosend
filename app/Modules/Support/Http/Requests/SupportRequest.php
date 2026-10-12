<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the Support form requests. Authorization is done by the route (`can:`) and the controller's
 * record-level checks, never by input; the request only validates shape.
 */
abstract class SupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
