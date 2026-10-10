<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Models\AppEntitlement;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\AppVersion;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Crm\CrmLifecycle;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

beforeEach(function () {
    CrmLifecycle::reset();
    $this->registerModule(CrmServiceProvider::class);
    app(AppCatalogSynchronizer::class)->sync();
    $this->crm = CatalogApp::query()->where('module_key', 'test_crm')->sole();
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
});

it('installs a free app and gates every tenant independently', function () {
    $installation = $this->inTenant(
        $this->acme,
        fn (): AppInstallation => app(AppStoreManager::class)->installFree($this->crm, 'community'),
    );

    expect($installation->status)->toBe(InstallationStatus::Active)
        ->and(CrmLifecycle::$calls)->toBe(['installing', 'installed', 'activating', 'activated'])
        ->and(AppInstallation::forTenant($this->acme)->count())->toBe(1)
        ->and(AppInstallation::forTenant($this->globex)->count())->toBe(0)
        ->and(AppEntitlement::forTenant($this->acme)->where('status', EntitlementStatus::Active)->count())->toBe(1)
        ->and(app(EntitlementChecker::class)->allows($this->acme, 'test_crm'))->toBeTrue()
        ->and(app(EntitlementChecker::class)->allows($this->globex, 'test_crm'))->toBeFalse();
});

it('deactivates, reactivates, uninstalls and reinstalls without deleting data or the licence', function () {
    $this->inTenant($this->acme, function (): void {
        $manager = app(AppStoreManager::class);
        $manager->installFree($this->crm, 'community');
        $manager->deactivate($this->crm);

        expect(app(EntitlementChecker::class)->allows($this->acme, 'test_crm'))->toBeFalse();

        $manager->activate($this->crm);
        $manager->uninstall($this->crm);

        expect(app(EntitlementChecker::class)->allows($this->acme, 'test_crm'))->toBeFalse();

        $manager->reinstall($this->crm);
    });

    $installation = AppInstallation::forTenant($this->acme)->sole();
    $entitlement = AppEntitlement::forTenant($this->acme)->sole();

    expect($installation->status)->toBe(InstallationStatus::Active)
        ->and($installation->uninstalled_at)->toBeNull()
        ->and($entitlement->status)->toBe(EntitlementStatus::Active)
        ->and(app(EntitlementChecker::class)->allows($this->acme, 'test_crm'))->toBeTrue()
        ->and(CrmLifecycle::$calls)->toContain(
            'deactivating',
            'deactivated',
            'activating',
            'activated',
            'uninstalling',
            'uninstalled',
        );
});

it('requires dependencies and prevents removing one while a dependent app is active', function () {
    app(ModuleRegistry::class)->register(new ModuleManifest(
        key: 'reports',
        name: 'Reports',
        version: '1.0.0',
        dependencies: ['core', 'test_crm'],
        description: 'Build reports from CRM pipeline data.',
        category: AppCategory::Reporting,
        offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
    ));
    app(AppCatalogSynchronizer::class)->sync();
    $reports = CatalogApp::query()->where('module_key', 'reports')->sole();

    $this->inTenant($this->acme, function () use ($reports): void {
        $manager = app(AppStoreManager::class);

        expect(fn () => $manager->installFree($reports, 'free'))
            ->toThrow(ValidationException::class, 'Activate the required apps first: test_crm');

        $manager->installFree($this->crm, 'community');
        $manager->installFree($reports, 'free');

        expect(fn () => $manager->deactivate($this->crm))
            ->toThrow(ValidationException::class, 'Deactivate dependent apps first: Reports');
    });
});

it('runs the upgrade hook when an installed app moves to a newer immutable version', function () {
    $this->inTenant($this->acme, fn () => app(AppStoreManager::class)->installFree($this->crm, 'community'));
    $current = $this->crm->currentVersion;
    /** @var AppVersion $next */
    $next = $current->replicate(['id', 'created_at', 'updated_at']);
    $next->version = '0.2.0';
    $next->manifest_hash = hash('sha256', 'crm-0.2.0');
    $next->published_at = now();
    $next->save();
    $this->crm->update(['current_version_id' => $next->id]);
    $this->crm->setRelation('currentVersion', $next);

    $installation = $this->inTenant(
        $this->acme,
        fn (): AppInstallation => app(AppStoreManager::class)->upgrade($this->crm),
    );

    expect($installation->app_version_id)->toBe($next->id)
        ->and(CrmLifecycle::$calls)->toContain('upgraded:0.1.0')
        ->and($this->auditTrail($this->acme, 'app_store.app_upgraded'))->toHaveCount(1);
});
