<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees central and Super Admin requests run with no tenant active,
 * even on long-lived workers where a previous request may have left one.
 */
final class EnsureCentralContext
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenancy->initialized) {
            $this->tenancy->end();
        }

        return $next($request);
    }
}
