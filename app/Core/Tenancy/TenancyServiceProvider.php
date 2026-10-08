<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Tenancy\Jobs\EnforceTenantContextOnJobs;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Tenancy\Support\TenantAwareRateLimiter;
use App\Core\Tenancy\Support\TenantConnectionResolver;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(TenantHost::class);
        $this->app->scoped(TenantConnectionResolver::class);
        $this->app->scoped(TenantStorage::class);

        // A cache that is never tenant-prefixed, for platform-level data such
        // as workspace handoff tokens. It owns its stores, so the per-tenant
        // prefix swap on the default manager does not reach it.
        $this->app->singleton('cache.central', static function ($app): Repository {
            return (new CacheManager($app))->store();
        });

        // The one rate limiter of the application. It answers for the
        // framework's class through extend(), not a binding of that name: the
        // framework registers its own from a deferred provider, which would
        // replace one.
        $this->app->singleton(TenantAwareRateLimiter::class, static fn ($app): TenantAwareRateLimiter => new TenantAwareRateLimiter(
            $app->make('cache')->driver($app['config']->get('cache.limiter')),
        ));

        $this->app->extend(RateLimiter::class, static fn (RateLimiter $limiter, $app): TenantAwareRateLimiter => $app->make(TenantAwareRateLimiter::class));
    }

    public function boot(): void
    {
        // Run the configured bootstrappers whenever a tenant context starts or ends.
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        // Registered after the package's own JobProcessing listener, which
        // restores the tenant from the payload first.
        Event::listen(JobProcessing::class, EnforceTenantContextOnJobs::class);

        Route::pattern('tenant', $this->app->make(TenantHost::class)->routePattern());
    }
}
