<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Modules\Support\Jobs\MonitorSla;
use App\Modules\Support\Jobs\RunMaintenance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

it('declares its schedules in the catalogue and queues them for an entitled workspace only', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->where('module_key', 'support')->with('currentVersion')->sole();

    expect(collect($app->currentVersion?->scheduled_jobs)->pluck('key')->all())->toBe(['support.sla', 'support.maintenance']);

    // The clock moves first: a licence is only effective from the moment it was granted.
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00', 'Asia/Dhaka'));
    $licensed = $this->createTenant('sup-licensed', ['timezone' => 'Asia/Dhaka']);
    $this->inTenant($licensed, fn () => app(AppStoreManager::class)->installFree($app, 'included'));
    $plain = $this->createTenant('sup-unlicensed', ['timezone' => 'Asia/Dhaka']);
    Queue::fake();

    $this->artisan('apps:run-scheduled', ['--tenant' => $plain->slug])->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('apps:run-scheduled', ['--tenant' => $licensed->slug])->assertSuccessful();

    // 10:00 local: the every-minute SLA monitor is due, the daily maintenance only at midnight.
    Queue::assertPushed(RunScheduledAppJob::class, 1);
    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'support.sla'
        && $job->displayName() === MonitorSla::class);

    $this->travelTo(CarbonImmutable::parse('2026-03-16 00:00', 'Asia/Dhaka'));
    $this->artisan('apps:run-scheduled', ['--tenant' => $licensed->slug])->assertSuccessful();

    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'support.maintenance'
        && $job->displayName() === RunMaintenance::class);
});
