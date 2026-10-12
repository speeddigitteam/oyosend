<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Core\Support\Toast;
use App\Modules\Ai\Enums\AiState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An AI request refused before or while calling the provider. Rendered as JSON `{state, message}` with a
 * matching status for API callers, or as a redirect back with a toast and an `ai` error for pages.
 */
final class AiRefusal extends RuntimeException
{
    public function __construct(public readonly AiState $state)
    {
        parent::__construct($state->message());
    }

    /** A refusal is an expected outcome, not an error to report. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return new JsonResponse(['state' => $this->state->value, 'message' => $this->getMessage()], $this->state->httpStatus());
        }

        Toast::error($this->getMessage());

        return back()->withErrors(['ai' => $this->getMessage()]);
    }
}
