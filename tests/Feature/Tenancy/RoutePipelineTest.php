<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Http\Middleware\HandleTenantInertia;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Tenancy\Concerns\ScopedToTenant;
use App\Core\Tenancy\Http\Middleware\EnsureCentralContext;
use App\Core\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Core\Tenancy\Http\Middleware\InitializeTenancy;
use App\Platform\Http\Middleware\EnsurePlatformTwoFactor;
use App\Platform\Http\Middleware\InitializePlatformContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/**
 * The middleware of a route in the order it will really run (aliases and
 * groups expanded, priority sorting applied), without parameters.
 *
 * @return list<string>
 */
function pipelineOf(RoutingRoute $route): array
{
    return array_map(
        static fn (string $middleware): string => explode(':', $middleware, 2)[0],
        app('router')->gatherRouteMiddleware($route),
    );
}

/**
 * @return list<RoutingRoute>
 */
function routesOnHost(string $domain): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => $route->getDomain() === $domain,
    ));
}

// Middleware groups, aliases and the priority list reach the router when the HTTP kernel is built.
beforeEach(fn () => app(HttpKernel::class));

/** Workspace routes a signed-out visitor may reach. Anything else must authenticate. */
const PUBLIC_WORKSPACE_ROUTES = [
    'auth.login', 'auth.login.store',
    'auth.two-factor.challenge', 'auth.two-factor.verify',
    'auth.password.request', 'auth.password.email', 'auth.password.reset', 'auth.password.update',
    'auth.invitations.show', 'auth.invitations.accept',
    'auth.handoff',
    'auth.impersonate',
    'core.locale.update',
    'projects.portal.show', 'projects.portal.comments.store',
];

/**
 * Payment gateway callbacks. They run on the workspace host with tenant
 * resolution and nothing else - no session, no cookies, no Inertia - because a
 * gateway returns the payer with a cross-site POST. See routes/tenant-callbacks.php.
 */
const STATELESS_WORKSPACE_ROUTES = [
    'core.billing.payments.return',
    'core.billing.gateways.notify',
    // Support: the single-use satisfaction survey link (token only, no session).
    'support.csat.show', 'support.csat.store',
    // Communication: provider webhooks (signature-verified) and the one-click unsubscribe page (token).
    'communication.webhooks.receive',
    'communication.webhooks.verify',
    'communication.unsubscribe.show',
    'communication.unsubscribe.store',
    // Email Marketing: signed click redirect and web version of an email (HMAC token only).
    'email_marketing.track.click',
    'email_marketing.web.show',
    // Automation: inbound workflow webhooks (HMAC-signed, replay-windowed).
    'automation.hooks.receive',
];

it('settles the tenant before anything that depends on it', function () {
    $routes = routesOnHost('{tenant}.localhost');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $pipeline = pipelineOf($route);
        $at = static fn (string $middleware): int|false => array_search($middleware, $pipeline, true);
        $name = (string) $route->getName();

        if (in_array($name, STATELESS_WORKSPACE_ROUTES, true)) {
            // The tenant from the hostname, a throttle, and no state of any kind.
            expect($at(InitializeTenancy::class))->not->toBeFalse("[{$name}] does not resolve a tenant")
                ->and($at(InitializeTenancy::class))->toBeLessThan($at(ThrottleRequests::class))
                ->and($pipeline)->not->toContain(StartSession::class, Authenticate::class, HandleTenantInertia::class);

            continue;
        }

        expect($at(InitializeTenancy::class))->not->toBeFalse("[{$name}] does not resolve a tenant")
            ->and($at(EnsureTenantIsActive::class))->not->toBeFalse("[{$name}] is reachable on a suspended workspace")
            ->and($at(HandleTenantInertia::class))->not->toBeFalse("[{$name}] has no tenant Inertia middleware");

        // Session first (the session is central), then the tenant, then everything tenant-aware.
        expect($at(StartSession::class))->toBeLessThan($at(InitializeTenancy::class), "[{$name}] starts the session after the tenant");

        foreach ([Authenticate::class, AuthenticateSession::class, ThrottleRequests::class, SubstituteBindings::class, Authorize::class, EnsureTenantMember::class] as $dependent) {
            if ($at($dependent) !== false) {
                expect($at(InitializeTenancy::class))->toBeLessThan(
                    $at($dependent),
                    "[{$name}] runs ".class_basename($dependent).' before the tenant is known',
                );
            }
        }
    }
});

it('requires sign-in and a membership check on every workspace route that is not explicitly public', function () {
    foreach (routesOnHost('{tenant}.localhost') as $route) {
        $pipeline = pipelineOf($route);
        $name = (string) $route->getName();

        if (in_array($name, [...PUBLIC_WORKSPACE_ROUTES, ...STATELESS_WORKSPACE_ROUTES], true)) {
            expect($pipeline)->not->toContain(Authenticate::class);

            continue;
        }

        expect($pipeline)
            ->toContain(Authenticate::class, AuthenticateSession::class, EnsureTenantMember::class);

        $member = array_search(EnsureTenantMember::class, $pipeline, true);

        expect(array_search(Authenticate::class, $pipeline, true))->toBeLessThan($member, "[{$name}] checks membership before authenticating");

        // A permission is only ever checked for someone already verified as a member here.
        if (($authorize = array_search(Authorize::class, $pipeline, true)) !== false) {
            expect($member)->toBeLessThan($authorize, "[{$name}] authorizes before the membership check")
                ->and(array_search(SubstituteBindings::class, $pipeline, true))->toBeLessThan($authorize);
        }
    }
});

it('binds only tenant-scoped models on workspace routes', function () {
    $bound = [];

    foreach (routesOnHost('{tenant}.localhost') as $route) {
        foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
            $bound[] = $class = (string) $parameter->getType();

            $scoped = in_array(ScopedToTenant::class, class_uses_recursive($class), true)
                // Role carries no automatic scope by design; it isolates in resolveRouteBinding().
                || $class === Role::class;

            expect($scoped)->toBeTrue("[{$route->getName()}] binds {$class}, which is not tenant-scoped");
        }
    }

    // The check above is not vacuous.
    expect(array_unique($bound))->toContain(Role::class)->not->toBeEmpty();
});

it('puts the admin panel in the central context behind the platform guard and two-factor', function () {
    $open = ['platform.login', 'platform.login.store', 'platform.two-factor.challenge', 'platform.two-factor.verify', 'platform.locale.update'];
    $beforeEnrolment = ['platform.logout', 'platform.two-factor.setup', 'platform.two-factor.confirm'];

    $routes = array_filter(
        routesOnHost('admin.localhost'),
        static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'platform.'),
    );

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $pipeline = pipelineOf($route);
        $name = (string) $route->getName();
        $at = static fn (string $middleware): int|false => array_search($middleware, $pipeline, true);

        expect($at(InitializePlatformContext::class))->not->toBeFalse("[{$name}] does not enter the platform context")
            ->and($pipeline)->not->toContain(InitializeTenancy::class);

        if (in_array($name, $open, true)) {
            expect($pipeline)->not->toContain(Authenticate::class);

            continue;
        }

        expect($at(InitializePlatformContext::class))->toBeLessThan($at(Authenticate::class), "[{$name}] authenticates before the guard is switched");

        if (in_array($name, $beforeEnrolment, true)) {
            continue;
        }

        expect($at(EnsurePlatformTwoFactor::class))->not->toBeFalse("[{$name}] is reachable without two-factor")
            ->and($at(Authenticate::class))->toBeLessThan($at(EnsurePlatformTwoFactor::class))
            ->and($at(Authorize::class))->not->toBeFalse("[{$name}] names no platform ability")
            ->and($at(EnsurePlatformTwoFactor::class))->toBeLessThan($at(Authorize::class));
    }
});

it('mounts Horizon on the admin host behind the same guard and two-factor', function () {
    $routes = array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'horizon.'),
    );

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $pipeline = pipelineOf($route);

        expect($route->getDomain())->toBe('admin.localhost')
            ->and($pipeline)->toContain(InitializePlatformContext::class, Authenticate::class, EnsurePlatformTwoFactor::class);
    }
});

it('keeps the central site out of any tenant context', function () {
    $routes = routesOnHost('localhost');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect(pipelineOf($route))
            ->toContain(EnsureCentralContext::class)
            ->not->toContain(InitializeTenancy::class);
    }
});
