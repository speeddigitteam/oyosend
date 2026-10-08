<?php

declare(strict_types=1);

namespace App\Core\Access\Http\Middleware;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `entitled:{module}` - the tenant must hold the module's entitlement.
 *
 * Applied to every route of a non-core module. The response is a 404 rather
 * than a 403 so the existence of an app the tenant has not installed is not
 * disclosed by its URLs.
 */
final class EnsureModuleEntitled
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->entitlements->allows($this->context->require(), $module), 404);

        return $next($request);
    }
}
