<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\AppVersion;
use App\Core\AppStore\Models\CatalogApp;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

beforeEach(function () {
    $this->registerModule(CrmServiceProvider::class);
});

it('syncs compiled optional manifests into an immutable catalogue', function () {
    $first = app(AppCatalogSynchronizer::class)->sync();
    $second = app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->with('currentVersion')->sole();

    expect($first)->toBe(['apps' => 1, 'versions' => 1, 'unpublished' => 0])
        ->and($second)->toBe(['apps' => 1, 'versions' => 0, 'unpublished' => 0])
        ->and($app->module_key)->toBe('crm')
        ->and($app->currentVersion->version)->toBe('0.1.0')
        ->and($app->currentVersion->offers)->toHaveCount(4)
        ->and(AppVersion::query()->count())->toBe(1);
});

it('exposes the sync as an idempotent deployment command', function () {
    expect(Artisan::call('apps:sync'))->toBe(0)
        ->and(Artisan::output())->toContain('Synced 1 app(s), published 1 new version(s), unpublished 0')
        ->and(Artisan::call('apps:sync', ['--prune' => true]))->toBe(0)
        ->and(CatalogApp::query()->where('is_published', true)->count())->toBe(1);
});

it('refuses to prune an app that still has a live installation or licence', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $tenant = $this->createTenant('acme');
    $retired = CatalogApp::query()->create([
        'module_key' => 'retired_app',
        'name' => 'Retired app',
        'description' => 'A compiled app that is no longer in this build.',
        'category' => AppCategory::Utilities,
        'icon' => 'archive',
        'is_featured' => false,
        'is_popular' => false,
        'is_published' => true,
    ]);
    $installation = $this->inTenant($tenant, fn () => AppInstallation::query()->create([
        'app_id' => $retired->id,
        'status' => InstallationStatus::Active,
        'installed_at' => now(),
        'activated_at' => now(),
    ]));

    expect(fn () => app(AppCatalogSynchronizer::class)->sync(prune: true))
        ->toThrow(RuntimeException::class, 'Cannot unpublish apps with live installations or licences: retired_app');

    $installation->update(['status' => InstallationStatus::Uninstalled, 'uninstalled_at' => now()]);
    $result = app(AppCatalogSynchronizer::class)->sync(prune: true);

    expect($result['unpublished'])->toBe(1)
        ->and($retired->refresh()->is_published)->toBeFalse();
});
