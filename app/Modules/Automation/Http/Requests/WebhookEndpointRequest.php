<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use App\Modules\Automation\Engine\Outbound\UrlGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Register or edit an outbound webhook endpoint. Input shape only: authorization is the route's
 * `can:` permission. The URL must be https to a public host name; DNS is checked again at call time.
 */
final class WebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = app(UrlGuard::class)->syntaxProblem((string) $value);

                if ($problem !== null) {
                    $fail(__($problem));
                }
            }],
            'secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
