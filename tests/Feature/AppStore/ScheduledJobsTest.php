<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleManifest;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Fixtures\Crm\CrmScheduledJob;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;
use Tests\Support\Fixtures\RecordsTenantJob;

beforeEach(function (): void {
    RecordsTenantJob::$runs = [];
    $this->registerModule(CrmServiceProvider::class);
});

afterEach(function (): void {
    RecordsTenantJob::$runs = [];
});

it('dispatches a compiled scheduled job only for accessible entitled tenants', function () {
    $entitled = $this->createTenant('scheduled-entitled');
    $unlicensed = $this->createTenant('scheduled-unlicensed');
    $suspended = $this->createTenant('scheduled-suspended', ['status' => TenantStatus::Suspended]);

    $this->entitle($entitled, 'test_crm');
    $this->entitle($suspended, 'test_crm');

    $this->artisan('apps:run-scheduled')
        ->expectsOutputToContain('Dispatched 1 app job(s), 0 failed.')
        ->assertSuccessful();

    expect(CrmScheduledJob::tenants())->toBe([$entitled->id])
        ->and(CrmScheduledJob::tenants())->not->toContain($unlicensed->id, $suspended->id);
});

it('dispatches each tenant job only once in a schedule slot', function () {
    $tenant = $this->createTenant('scheduled-once');
    $this->entitle($tenant, 'test_crm');

    $this->artisan('apps:run-scheduled')->assertSuccessful();
    $this->artisan('apps:run-scheduled')->assertSuccessful();

    expect(CrmScheduledJob::tenants())->toBe([$tenant->id]);

    $this->travel(1)->minute();
    $this->artisan('apps:run-scheduled')->assertSuccessful();

    expect(CrmScheduledJob::tenants())->toBe([$tenant->id, $tenant->id]);
});

it('rechecks the module entitlement when the queued schedule reaches a worker', function () {
    $tenant = $this->createTenant('scheduled-revoked');
    $checker = new class implements EntitlementChecker
    {
        public bool $allowed = true;

        public function allows(Tenant $tenant, string $moduleKey): bool
        {
            return $this->allowed && $tenant->isAccessible() && $moduleKey === 'test_crm';
        }

        public function entitledModules(Tenant $tenant): array
        {
            return $this->allowed && $tenant->isAccessible() ? ['core', 'test_crm'] : ['core'];
        }
    };
    $this->app->instance(EntitlementChecker::class, $checker);
    Queue::fake();

    $this->artisan('apps:run-scheduled')->assertSuccessful();

    $queued = null;
    Queue::assertPushed(RunScheduledAppJob::class, function (RunScheduledAppJob $job) use (&$queued): bool {
        $queued = $job;

        return $job->module === 'test_crm' && $job->definition === 'test_crm.pipeline_refresh';
    });
    expect($queued)->toBeInstanceOf(RunScheduledAppJob::class);

    if (! $queued instanceof RunScheduledAppJob) {
        throw new RuntimeException('The entitlement-gated schedule wrapper was not queued.');
    }

    $checker->allowed = false;
    $this->inTenant($tenant, fn () => app()->call([$queued, 'handle']));

    expect(CrmScheduledJob::tenants())->toBe([]);
});

it('can limit a schedule run to one workspace', function () {
    $first = $this->createTenant('scheduled-first');
    $second = $this->createTenant('scheduled-second');
    $this->entitle($first, 'test_crm');
    $this->entitle($second, 'test_crm');

    $this->artisan('apps:run-scheduled', ['--tenant' => $second->slug])->assertSuccessful();

    expect(CrmScheduledJob::tenants())->toBe([$second->id]);
});

it('rejects jobs that are not both queued and tenant-bound', function () {
    expect(fn () => new ScheduledJobDefinition(
        'crm.invalid',
        RecordsTenantJob::class,
        ScheduledJobFrequency::EveryMinute,
    ))->toThrow(InvalidArgumentException::class, 'must implement ShouldQueue and RequiresTenantContext');
});

it('requires schedule keys to belong to their declaring module', function () {
    expect(fn () => new ModuleManifest(
        key: 'crm',
        name: 'CRM',
        version: '1.0.0',
        scheduledJobs: [
            new ScheduledJobDefinition('sales.refresh', CrmScheduledJob::class),
        ],
    ))->toThrow(InvalidArgumentException::class, 'must be prefixed with its module key [crm.]');
});

it('publishes safe schedule metadata with the immutable app version', function () {
    app(AppCatalogSynchronizer::class)->sync();

    $version = CatalogApp::query()
        ->where('module_key', 'test_crm')
        ->firstOrFail()
        ->currentVersion()
        ->firstOrFail();

    expect($version->scheduled_jobs)->toEqual([[
        'key' => 'test_crm.pipeline_refresh',
        'job' => CrmScheduledJob::class,
        'frequency' => ScheduledJobFrequency::EveryMinute->value,
    ]]);
});

it('registers the idempotent app scheduler every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'apps:run-scheduled'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
