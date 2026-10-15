<?php

use App\Core\Access\Http\Middleware\EnsureModuleEntitled;
use App\Core\Billing\Http\Middleware\EnsureWorkspaceIsSubscribed;
use App\Core\Http\Middleware\HandleCentralInertia;
use App\Core\Http\Middleware\HandleTenantInertia;
use App\Core\Http\Middleware\NoIndex;
use App\Core\Http\Middleware\SecurityHeaders;
use App\Core\Http\Middleware\SetLocale;
use App\Core\Http\Middleware\ThrottleRequestsPerRoute;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Support\ErrorPageResponder;
use App\Core\Tenancy\Http\Middleware\EnsureCentralContext;
use App\Core\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Core\Tenancy\Http\Middleware\InitializeTenancy;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Website\Blog\BlogMedia;
use App\Core\Website\Http\Controllers\BlogMediaController;
use App\Core\Website\Http\Controllers\BrandAssetController;
use App\Core\Website\Http\Middleware\SetSiteLocale;
use App\Core\Website\SiteBrand;
use App\Platform\Console\BenchmarkCommand;
use App\Platform\Console\CreatePlatformAdminCommand;
use App\Platform\Console\VerifyBackupCommand;
use App\Platform\Http\Controllers\HealthController;
use App\Platform\Http\Middleware\EnsurePlatformTwoFactor;
use App\Platform\Http\Middleware\HandlePlatformInertia;
use App\Platform\Http\Middleware\InitializePlatformContext;
use App\Platform\Impersonation\Http\EnforceSupportSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            $hosts = app(TenantHost::class);

            // Probes for a load balancer or monitor: any host, no session, no tenant.
            Route::get('health', [HealthController::class, 'live'])->name('health.live');
            Route::get('health/ready', [HealthController::class, 'ready'])
                ->middleware('throttle:60,1')
                ->name('health.ready');

            // Super Admin panel: admin.APP_DOMAIN
            Route::domain($hosts->adminHost())
                ->middleware(['web', 'platform'])
                ->name('platform.')
                ->group(base_path('routes/platform.php'));

            // Central / marketing site: APP_DOMAIN
            Route::domain($hosts->domain())
                ->middleware(['web', 'central'])
                ->name('central.')
                ->group(base_path('routes/central.php'));

            // The uploaded logo and favicon, loaded by every host: stateless, cached for good (docs/WEBSITE.md).
            Route::domain($hosts->domain())
                ->middleware('central.callback')
                ->get('brand/{file}', BrandAssetController::class)
                ->where('file', SiteBrand::FILE_PATTERN)
                ->name('central.brand');

            // The blog's images: stateless and cached for good, like the logo, with room for a page full of them.
            Route::domain($hosts->domain())
                ->middleware([EnsureCentralContext::class, 'throttle:600,1'])
                ->get('blog/media/{file}', BlogMediaController::class)
                ->where('file', BlogMedia::FILE_PATTERN)
                ->name('central.blog.media');

            // Tenant workspaces ({tenant}.APP_DOMAIN) are registered by the
            // module providers - see App\Core\Modules\ModuleServiceProvider.
        },
    )
    ->withCommands([
        CreatePlatformAdminCommand::class,
        BenchmarkCommand::class,
        VerifyBackupCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Reverse proxies are trusted from config/trustedproxy.php (TRUSTED_PROXIES),
        // which the framework's TrustProxies middleware reads by itself.
        $middleware->web(append: [SecurityHeaders::class]);

        // Only the central site belongs in search results (docs/WEBSITE.md).
        $middleware->group('tenant', [
            NoIndex::class,
            InitializeTenancy::class,
            SetLocale::class,
            HandleTenantInertia::class,
            EnsureTenantIsActive::class,
            EnforceSupportSession::class,
        ]);

        // Payment gateway callbacks: the tenant from the hostname and nothing
        // else - no session, no cookies. See routes/tenant-callbacks.php.
        $middleware->group('tenant.callback', [
            NoIndex::class,
            InitializeTenancy::class,
            'throttle:60,1',
        ]);

        // Central-host stateless callbacks (the Social OAuth redirect broker): no session, no cookies.
        $middleware->group('central.callback', [
            EnsureCentralContext::class,
            'throttle:60,1',
        ]);

        $middleware->group('central', [
            EnsureCentralContext::class,
            SetLocale::class,
            HandleCentralInertia::class,
        ]);

        $middleware->group('platform', [
            NoIndex::class,
            InitializePlatformContext::class,
            SetLocale::class,
            HandlePlatformInertia::class,
        ]);

        $middleware->alias([
            'member' => EnsureTenantMember::class,
            'entitled' => EnsureModuleEntitled::class,
            'subscribed' => EnsureWorkspaceIsSubscribed::class,
            'platform.2fa' => EnsurePlatformTwoFactor::class,
            'site.locale' => SetSiteLocale::class,
            // One counter per route and limit, not one per user for every route (see the class).
            'throttle' => ThrottleRequestsPerRoute::class,
        ]);

        // The context (tenant, central or platform) must be settled before
        // authentication and route-model binding run: both depend on it.
        foreach ([InitializeTenancy::class, EnsureCentralContext::class, InitializePlatformContext::class] as $context) {
            $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: $context);
        }

        // A signed-out visitor must be sent to sign in before the app's route is
        // judged, or 404 versus 302 would tell them which apps a workspace has.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: EnsureModuleEntitled::class);

        $onAdminHost = static fn (Request $request): bool => $request->getHost() === app(TenantHost::class)->adminHost();

        $middleware->redirectGuestsTo(
            static fn (Request $request): string => $onAdminHost($request) ? route('platform.login') : route('auth.login'),
        );

        $middleware->redirectUsersTo(
            static fn (Request $request): string => $onAdminHost($request) ? route('platform.dashboard') : route('core.dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(
            static fn (Response $response, Throwable $exception, Request $request): Response => app(ErrorPageResponder::class)
                ->respond($response, $request),
        );
    })->create();
