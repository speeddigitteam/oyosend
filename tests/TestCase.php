<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Sleep;
use Inertia\Inertia;
use RuntimeException;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithModules;
use Tests\Support\InteractsWithPlatform;
use Tests\Support\InteractsWithTenants;
use Tests\Support\InteractsWithTwoFactor;

abstract class TestCase extends BaseTestCase
{
    use AssertsTenantIsolation;
    use InteractsWithBilling;
    use InteractsWithModules;
    use InteractsWithPlatform;
    use InteractsWithTenants;
    use InteractsWithTwoFactor;

    private string $configuredGuard;

    private string $configuredLocale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuredGuard = (string) config('auth.defaults.guard');
        $this->configuredLocale = (string) config('app.locale');

        // No built assets in the test run; pages still render through the root view.
        $this->withoutVite();

        // CredentialVerifier pads every password check to a fixed minimum time.
        Sleep::fake();
    }

    /**
     * RefreshDatabase runs `migrate:fresh`. Make it impossible for that to reach
     * a real database, whatever .env, a cached config or the shell says.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (preg_match('/(^|_)testing(_test_\w+)?$/', $database) !== 1) {
            throw new RuntimeException(
                "Refusing to run tests against database [{$database}]: its name must end in \"_testing\". "
                .'Check phpunit.xml and run `php artisan config:clear`.',
            );
        }
    }

    /**
     * Every simulated HTTP request starts the way a real one does.
     *
     * A test makes several requests against one application instance, while
     * production serves each request from a fresh one. State that only lives
     * for a request there is reset here, so a request cannot pass (or fail)
     * because of something an earlier request in the same test left behind.
     *
     * The tenant context is deliberately NOT reset: the context middleware is
     * supposed to cope with a tenant left active by a previous request, and
     * leaving it in place keeps that under test.
     *
     * {@inheritDoc}
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->beginRequestLifecycle();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function beginRequestLifecycle(): void
    {
        // AccessChecker, PermissionResolver, AuditLogger, TenantContext, ...
        $this->app->forgetScopedInstances();

        // Props shared by the previous request's area middleware.
        Inertia::flushShared();
        View::share('area', null);

        // InitializePlatformContext and `auth:` switch the default guard; SetLocale the locale.
        Auth::shouldUse($this->configuredGuard);
        App::setLocale($this->configuredLocale);
    }
}
