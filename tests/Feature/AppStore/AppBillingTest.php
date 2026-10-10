<?php

declare(strict_types=1);

use App\Core\AppStore\AppBillingCycle;
use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\InstallationDeactivationReason;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Enums\SubscriptionItemStatus;
use App\Core\AppStore\Models\AppEntitlement;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\PaymentProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
    $this->registerModule(CrmServiceProvider::class);
    app(AppCatalogSynchronizer::class)->sync();
    $this->crm = CatalogApp::query()->where('module_key', 'crm')->sole();
    $this->acme = $this->createTenant('acme');
});

it('activates a paid app only after server-side payment verification', function () {
    $invoice = $this->inTenant(
        $this->acme,
        fn () => app(AppStoreManager::class)->purchase($this->crm, 'monthly'),
    );

    expect($invoice)->not->toBeNull()
        ->and($invoice->kind)->toBe(InvoiceKind::AppPurchase)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->total_amount)->toBe(250000)
        ->and(AppInstallation::forTenant($this->acme)->sole()->status)->toBe(InstallationStatus::PendingPayment)
        ->and(AppEntitlement::forTenant($this->acme)->count())->toBe(0);

    $this->inTenant($this->acme, fn () => app(PaymentProcessor::class)->recordOffline(
        $invoice,
        $this->createPlatformUser(),
        'bank_transfer',
    ));

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(AppInstallation::forTenant($this->acme)->sole()->status)->toBe(InstallationStatus::Active)
        ->and(AppEntitlement::forTenant($this->acme)->sole()->status)->toBe(EntitlementStatus::Active)
        ->and(SubscriptionItem::forTenant($this->acme)->sole()->status)->toBe(SubscriptionItemStatus::Active);
});

it('renews through an invoice, keeps access in grace, then expires without deleting data', function () {
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

    $this->travelTo($item->current_period_end->copy()->subDays(3));
    $this->inTenant($this->acme, fn () => app(AppBillingCycle::class)->run());

    $renewal = $this->inTenant(
        $this->acme,
        fn () => $item->invoices()->where('kind', InvoiceKind::AppRenewal)->sole(),
    );
    expect($renewal->status)->toBe(InvoiceStatus::Open);

    $this->travelTo($item->current_period_end);
    $this->inTenant($this->acme, fn () => app(AppBillingCycle::class)->run());
    $item->refresh();

    expect($item->status)->toBe(SubscriptionItemStatus::PastDue)
        ->and(AppEntitlement::forTenant($this->acme)->sole()->valid_until?->equalTo($item->grace_ends_at))->toBeTrue();

    $this->travelTo($item->grace_ends_at);
    $this->inTenant($this->acme, fn () => app(AppBillingCycle::class)->run());

    expect($item->refresh()->status)->toBe(SubscriptionItemStatus::Expired)
        ->and(AppInstallation::forTenant($this->acme)->sole()->status)->toBe(InstallationStatus::Inactive)
        ->and(AppEntitlement::forTenant($this->acme)->sole()->status)->toBe(EntitlementStatus::Expired)
        ->and(AppInstallation::forTenant($this->acme)->count())->toBe(1);
});

it('does not reactivate a manually deactivated app when its renewal is paid', function () {
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
    $this->inTenant($this->acme, fn () => app(AppStoreManager::class)->deactivate($this->crm));

    $this->travelTo($item->current_period_end->copy()->subDays(3));
    $this->inTenant($this->acme, fn () => app(AppBillingCycle::class)->run());
    $renewal = $this->inTenant(
        $this->acme,
        fn () => $item->invoices()->where('kind', InvoiceKind::AppRenewal)->sole(),
    );
    $this->inTenant($this->acme, fn () => app(PaymentProcessor::class)->recordOffline(
        $renewal,
        $this->createPlatformUser(),
        'bank_transfer',
    ));

    $installation = AppInstallation::forTenant($this->acme)->sole();
    expect($installation->status)->toBe(InstallationStatus::Inactive)
        ->and($installation->deactivation_reason)->toBe(InstallationDeactivationReason::Manual)
        ->and($item->refresh()->status)->toBe(SubscriptionItemStatus::Active)
        ->and(AppEntitlement::forTenant($this->acme)->where('status', EntitlementStatus::Active)->count())->toBe(1);
});

it('abandons a pending purchase cleanly when the app is uninstalled', function () {
    $invoice = $this->inTenant(
        $this->acme,
        fn () => app(AppStoreManager::class)->purchase($this->crm, 'monthly'),
    );

    $this->inTenant($this->acme, fn () => app(AppStoreManager::class)->uninstall($this->crm));

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Void)
        ->and(SubscriptionItem::forTenant($this->acme)->sole()->status)->toBe(SubscriptionItemStatus::Canceled)
        ->and(AppInstallation::forTenant($this->acme)->sole()->status)->toBe(InstallationStatus::Uninstalled);

    $replacement = $this->inTenant(
        $this->acme,
        fn () => app(AppStoreManager::class)->purchase($this->crm, 'monthly'),
    );

    expect($replacement)->not->toBeNull()
        ->and($replacement->status)->toBe(InvoiceStatus::Open)
        ->and(SubscriptionItem::forTenant($this->acme)->count())->toBe(2);
});

it('cancels a past-due subscription immediately without reissuing its renewal', function () {
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
    $this->travelTo($item->current_period_end);
    $this->inTenant($this->acme, fn () => app(AppBillingCycle::class)->run());
    $renewal = $this->inTenant(
        $this->acme,
        fn () => $item->invoices()->where('kind', InvoiceKind::AppRenewal)->sole(),
    );

    $this->inTenant($this->acme, fn () => app(AppStoreManager::class)->cancel($item));

    expect($item->refresh()->status)->toBe(SubscriptionItemStatus::Canceled)
        ->and($renewal->refresh()->status)->toBe(InvoiceStatus::Void)
        ->and(AppEntitlement::forTenant($this->acme)->sole()->status)->toBe(EntitlementStatus::Expired);

    $this->travel(1)->day();
    $this->inTenant($this->acme, fn () => app(AppBillingCycle::class)->run());
    $renewalCount = $this->inTenant(
        $this->acme,
        fn () => $item->invoices()->where('kind', InvoiceKind::AppRenewal)->count(),
    );
    expect($renewalCount)->toBe(1);
});

it('keeps verified money unapplied when the compiled app is absent', function () {
    $invoice = $this->inTenant(
        $this->acme,
        fn () => app(AppStoreManager::class)->purchase($this->crm, 'monthly'),
    );
    $this->crm->update(['module_key' => 'crm_removed_from_build']);

    $payment = $this->inTenant($this->acme, fn () => app(PaymentProcessor::class)->recordOffline(
        $invoice,
        $this->createPlatformUser(),
        'bank_transfer',
    ));

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Open)
        ->and(SubscriptionItem::forTenant($this->acme)->sole()->status)->toBe(SubscriptionItemStatus::Pending)
        ->and(AppInstallation::forTenant($this->acme)->sole()->status)->toBe(InstallationStatus::PendingPayment)
        ->and($this->auditTrail($this->acme, 'billing.payment_unapplied'))->toHaveCount(1);
});
