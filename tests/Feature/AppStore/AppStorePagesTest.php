<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\PaymentProcessor;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

beforeEach(function () {
    $this->registerModule(CrmServiceProvider::class);
    app(AppCatalogSynchronizer::class)->sync();
    $this->crm = CatalogApp::query()->where('module_key', 'test_crm')->sole();
    $this->acme = $this->createTenant('acme');
    $this->subscribe($this->acme, Plan::factory()->free()->create(), withTrial: false);
});

it('shows the compiled catalogue and app detail to a workspace member', function () {
    $this->actingAsOwner($this->acme)
        ->get($this->tenantUrl($this->acme, '/apps?q=Test%20CRM'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/AppStore/Index')
            ->has('apps', 1)
            ->where('apps.0.key', 'test_crm')
            ->where('apps.0.installation', null));

    $this->get($this->tenantUrl($this->acme, '/apps/test_crm'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/AppStore/Show')
            ->where('app.key', 'test_crm')
            ->has('app.offers', 4));
});

it('installs a free offer over the authorised tenant route', function () {
    $this->actingAsOwner($this->acme)
        ->post($this->tenantUrl($this->acme, '/apps/test_crm/install'), ['offer' => 'community'])
        ->assertRedirect($this->tenantUrl($this->acme, '/apps/test_crm'));

    $this->get($this->tenantUrl($this->acme, '/apps/my'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/AppStore/MyApps')
            ->has('apps', 1)
            ->where('apps.0.installation.status', 'active'));
});

it('does not expose unpublished or uncompiled catalogue rows', function () {
    $this->crm->update(['is_published' => false]);

    $this->actingAsOwner($this->acme)
        ->get($this->tenantUrl($this->acme, '/apps?q=Test%20CRM'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('apps', []));

    $this->get($this->tenantUrl($this->acme, '/apps/test_crm'))->assertNotFound();
});

it('requires billing control for paid app cancellation and uninstall', function () {
    $invoice = $this->inTenant(
        $this->acme,
        fn () => app(AppStoreManager::class)->purchase($this->crm, 'monthly'),
    );
    $this->inTenant($this->acme, fn () => app(PaymentProcessor::class)->recordOffline(
        $invoice,
        $this->createPlatformUser(),
        'bank_transfer',
    ));
    $item = SubscriptionItem::forTenant($this->acme)->sole();
    $appManager = $this->addMember(
        $this->acme,
        $this->createRole($this->acme, 'App manager', ['core.apps.view', 'core.apps.manage']),
    );

    $this->startNewBrowserSession()->actingAsMember($this->acme, $appManager)
        ->post($this->tenantUrl($this->acme, "/apps/subscriptions/{$item->id}/cancel"))
        ->assertForbidden();
    $this->delete($this->tenantUrl($this->acme, '/apps/test_crm'))->assertForbidden();

    expect($item->refresh()->cancel_at_period_end)->toBeFalse();
});
