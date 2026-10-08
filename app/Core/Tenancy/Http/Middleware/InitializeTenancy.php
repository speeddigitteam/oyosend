<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Http\Middleware;

use App\Core\Tenancy\Support\TenantHost;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request's subdomain and starts its context.
 *
 * The tenant always comes from the hostname - never from a header, a query
 * string, the session or anything else the client could set.
 */
final class InitializeTenancy
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly DomainTenantResolver $resolver,
        private readonly TenantHost $hosts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->hosts->tenantKey($request->getHost());

        abort_if($key === null, 404);

        try {
            $tenant = $this->resolver->resolve($key);
        } catch (TenantCouldNotBeIdentifiedException) {
            abort(404);
        }

        $this->tenancy->initialize($tenant);

        // The `{tenant}` domain parameter has done its job; keep it out of
        // controller signatures and implicit route-model binding.
        $request->route()?->forgetParameter('tenant');

        return $next($request);
    }
}
