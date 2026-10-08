<?php

use App\Core\Access\Http\Middleware\EnsureModuleEntitled;
use App\Core\Http\Middleware\HandleCentralInertia;
use App\Core\Http\Middleware\HandleTenantInertia;
use App\Core\Http\Middleware\SetLocale;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Support\ErrorPageResponder;
use App\Core\Tenancy\Http\Middleware\EnsureCentralContext;
use App\Core\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Core\Tenancy\Http\Middleware\InitializeTenancy;
use App\Core\Tenancy\Support\TenantHost;
use App\Platform\Console\CreatePlatformAdminCommand;
use App\Platform\Http\Middleware\EnsurePlatformTwoFactor;
use App\Platform\Http\Middleware\HandlePlatformInertia;
use App\Platform\Http\Middleware\InitializePlatformContext;
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

            // Tenant workspaces ({tenant}.APP_DOMAIN) are registered by the
            // module providers - see App\Core\Modules\ModuleServiceProvider.
        },
    )
    ->withCommands([
        CreatePlatformAdminCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('tenant', [
            InitializeTenancy::class,
            SetLocale::class,
            HandleTenantInertia::class,
            EnsureTenantIsActive::class,
        ]);

        $middleware->group('central', [
            EnsureCentralContext::class,
            SetLocale::class,
            HandleCentralInertia::class,
        ]);

        $middleware->group('platform', [
            InitializePlatformContext::class,
            HandlePlatformInertia::class,
        ]);

        $middleware->alias([
            'member' => EnsureTenantMember::class,
            'entitled' => EnsureModuleEntitled::class,
            'platform.2fa' => EnsurePlatformTwoFactor::class,
        ]);

        // The context (tenant, central or platform) must be settled before
        // authentication and route-model binding run: both depend on it.
        foreach ([InitializeTenancy::class, EnsureCentralContext::class, InitializePlatformContext::class] as $context) {
            $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: $context);
        }

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
