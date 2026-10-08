<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns HTTP error responses into the application's own error page.
 *
 * The page is self-contained (no layout, no shared props), because an error
 * can happen before any area - or any tenant - has been resolved.
 */
final class ErrorPageResponder
{
    private const array HANDLED = [403, 404, 429, 500, 503];

    public function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($status === 419) {
            Toast::warning(__('The page expired. Please try again.'));

            return back();
        }

        if (! in_array($status, self::HANDLED, true) || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return $response;
        }

        // Keep the framework's detailed exception page while developing.
        if ($status >= 500 && config('app.debug')) {
            return $response;
        }

        return Inertia::render('Core/Pages/Errors/Error', ['status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
    }
}
