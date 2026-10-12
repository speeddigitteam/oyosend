<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\PaymentProcessor;
use App\Modules\Ai\Models\Conversation;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithAi;

uses(InteractsWithAi::class);

beforeEach(function () {
    Mail::fake();
    app(AppCatalogSynchronizer::class)->sync();
    $this->aiApp = CatalogApp::query()->with('currentVersion')->where('module_key', 'ai')->sole();
});

it('is a paid AI Tools app whose offers are placeholders for the product owner', function () {
    $offers = collect($this->aiApp->currentVersion->offers)->keyBy('key');

    expect($this->aiApp->category->value)->toBe('ai_tools')
        ->and($offers->keys()->sort()->values()->all())->toBe(['monthly', 'trial', 'yearly'])
        ->and($offers['monthly']['type'])->toBe('monthly')
        ->and($offers['monthly']['currency'])->toBe('BDT')
        ->and($offers['monthly']['amount'])->toBe(199000)
        ->and($offers['yearly']['amount'])->toBe(1990000)
        ->and($offers['trial']['trial_days'])->toBe(14);
});

it('activates only after the invoice is verified as paid', function () {
    $tenant = $this->createTenant('ai-paid');
    $owner = $this->actingAsOwner($tenant);

    $owner->get($this->aiUrl($tenant))->assertNotFound();

    $invoice = $this->inTenant($tenant, fn () => app(AppStoreManager::class)->purchase($this->aiApp, 'monthly'));

    expect($invoice->kind)->toBe(InvoiceKind::AppPurchase)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->total_amount)->toBe(199000)
        ->and(AppInstallation::forTenant($tenant)->sole()->status)->toBe(InstallationStatus::PendingPayment);

    // Pending payment: still no access and no permission.
    $owner->get($this->aiUrl($tenant))->assertNotFound();

    $this->inTenant($tenant, fn () => app(PaymentProcessor::class)->recordOffline($invoice, $this->createPlatformUser(), 'bank_transfer'));

    expect(AppInstallation::forTenant($tenant)->sole()->status)->toBe(InstallationStatus::Active);
    $owner->get($this->aiUrl($tenant))->assertOk();
    $owner->get($this->aiUrl($tenant, '/usage'))->assertOk();
});

it('starts a free trial that grants access without an invoice', function () {
    $tenant = $this->createTenant('ai-trial');

    $invoice = $this->inTenant($tenant, fn () => app(AppStoreManager::class)->purchase($this->aiApp, 'trial'));

    expect($invoice)->toBeNull();
    $this->actingAsOwner($tenant)->get($this->aiUrl($tenant))->assertOk();
});

it('loses access when the app is uninstalled but keeps conversations for a reinstall', function () {
    $tenant = $this->createTenant('ai-uninstall');
    $this->inTenant($tenant, fn () => app(AppStoreManager::class)->purchase($this->aiApp, 'trial'));
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $this->aiConversation($tenant, $owner, 'Kept');

    $this->inTenant($tenant, fn () => app(AppStoreManager::class)->uninstall($this->aiApp));

    $this->actingAsOwner($tenant)->get($this->aiUrl($tenant))->assertNotFound();
    expect($this->inTenant($tenant, fn () => Conversation::query()->count()))->toBe(1);
});
