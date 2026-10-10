<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Billing\Models\Plan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

beforeEach(function () {
    $this->registerModule(CrmServiceProvider::class);
    app(AppCatalogSynchronizer::class)->sync();
    $this->crm = CatalogApp::query()->where('module_key', 'test_crm')->sole();
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $plan = Plan::factory()->free()->create();
    $this->subscribe($this->acme, $plan, withTrial: false);
    $this->subscribe($this->globex, $plan, withTrial: false);
    $this->installation = $this->inTenant(
        $this->acme,
        fn (): AppInstallation => app(AppStoreManager::class)->installFree($this->crm, 'community'),
    );
});

it('validates and encrypts tenant app settings without returning secret values', function () {
    $response = $this->actingAsOwner($this->acme)->put(
        $this->tenantUrl($this->acme, '/apps/test_crm/settings'),
        ['settings' => [
            'pipeline_view' => 'list',
            'daily_digest' => false,
            'webhook_secret' => 'crm-signing-secret',
        ]],
    );

    $response->assertRedirect()->assertSessionHasNoErrors();

    $installation = AppInstallation::forTenant($this->acme)->sole();
    $raw = DB::connection(config('tenancy.database.central_connection'))
        ->table('app_installations')
        ->where('id', $installation->id)
        ->value('settings');

    expect($installation->settings)->toBe([
        'pipeline_view' => 'list',
        'daily_digest' => false,
        'webhook_secret' => 'crm-signing-secret',
    ])->and($raw)->toBeString()
        ->and($raw)->not->toContain('crm-signing-secret');

    $page = $this->get($this->tenantUrl($this->acme, '/apps/test_crm'));
    $page->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('app.settings.0.value', 'list')
            ->where('app.settings.1.value', false)
            ->where('app.settings.2.hasValue', true)
            ->missing('app.settings.2.value')
            ->missing('app.settings.2.default'));
    expect($page->getContent())->not->toContain('crm-signing-secret');

    $audit = $this->auditTrail($this->acme, 'app_store.settings_updated')->sole();
    expect($audit->properties)->toEqual([
        'app' => 'test_crm',
        'changed_keys' => ['pipeline_view', 'daily_digest', 'webhook_secret'],
        'secret_keys' => ['webhook_secret'],
    ])->and($audit->toJson())->not->toContain('crm-signing-secret');
});

it('rejects unknown fields and values outside the compiled schema', function () {
    $this->actingAsOwner($this->acme)
        ->put($this->tenantUrl($this->acme, '/apps/test_crm/settings'), [
            'settings' => ['unknown' => 'value'],
        ])
        ->assertSessionHasErrors('settings');

    $this->put($this->tenantUrl($this->acme, '/apps/test_crm/settings'), [
        'settings' => ['pipeline_view' => 'calendar', 'daily_digest' => 'not-a-boolean'],
    ])->assertSessionHasErrors(['settings.pipeline_view', 'settings.daily_digest']);

    $this->put($this->tenantUrl($this->acme, '/apps/test_crm/settings'), [
        'settings' => ['daily_digest' => false],
    ])->assertSessionHasErrors('settings.pipeline_view');

    expect(AppInstallation::forTenant($this->acme)->sole()->settings)->toBe([
        'pipeline_view' => 'board',
        'daily_digest' => true,
    ])->and($this->auditTrail($this->acme, 'app_store.settings_updated'))->toBeEmpty();
});

it('never flashes a rejected secret value into the session', function () {
    $secret = str_repeat('sensitive-', 60);

    $response = $this->actingAsOwner($this->acme)->from($this->tenantUrl($this->acme, '/apps/test_crm'))
        ->put($this->tenantUrl($this->acme, '/apps/test_crm/settings'), [
            'settings' => [
                'pipeline_view' => 'board',
                'daily_digest' => true,
                'webhook_secret' => $secret,
            ],
        ]);

    $response->assertRedirect()->assertSessionHasErrors('settings.webhook_secret');
    expect(session()->getOldInput())->not->toContain($secret)
        ->and((string) $response->getContent())->not->toContain($secret);
});

it('only updates an installed app in the current workspace', function () {
    $this->actingAsOwner($this->globex)
        ->put($this->tenantUrl($this->globex, '/apps/test_crm/settings'), [
            'settings' => ['pipeline_view' => 'list', 'daily_digest' => false],
        ])
        ->assertSessionHasErrors('app');

    expect(AppInstallation::forTenant($this->acme)->sole()->settings['pipeline_view'])->toBe('board')
        ->and(AppInstallation::forTenant($this->globex)->count())->toBe(0);
});

it('requires the app management permission', function () {
    $viewerRole = $this->createRole($this->acme, 'App viewer', ['core.apps.view']);
    $viewer = $this->addMember($this->acme, $viewerRole);

    $this->startNewBrowserSession()
        ->actingAsMember($this->acme, $viewer)
        ->put($this->tenantUrl($this->acme, '/apps/test_crm/settings'), [
            'settings' => ['pipeline_view' => 'list', 'daily_digest' => false],
        ])
        ->assertForbidden();

    expect(AppInstallation::forTenant($this->acme)->sole()->settings['pipeline_view'])->toBe('board')
        ->and($this->auditTrail($this->acme, 'app_store.settings_updated'))->toBeEmpty();
});
