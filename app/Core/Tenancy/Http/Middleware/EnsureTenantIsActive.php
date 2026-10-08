<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every request to a workspace that is pending or suspended.
 */
final class EnsureTenantIsActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->require();

        if ($tenant->isAccessible()) {
            return $next($request);
        }

        return Inertia::render('Core/Pages/Errors/WorkspaceUnavailable', [
            'workspace' => $tenant->name,
            'status' => $tenant->status->value,
        ])->toResponse($request)->setStatusCode(403);
    }
}
