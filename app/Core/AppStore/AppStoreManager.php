<?php

declare(strict_types=1);

namespace App\Core\AppStore;

use App\Core\AppStore\Contracts\ModuleLifecycle;
use App\Core\AppStore\Contracts\NullModuleLifecycle;
use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppSettingType;
use App\Core\AppStore\Enums\EntitlementSource;
use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\InstallationDeactivationReason;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Enums\SubscriptionItemStatus;
use App\Core\AppStore\Events\AppActivated;
use App\Core\AppStore\Events\AppDeactivated;
use App\Core\AppStore\Events\AppInstalled;
use App\Core\AppStore\Events\AppUninstalled;
use App\Core\AppStore\Events\AppUpgraded;
use App\Core\AppStore\Models\AppEntitlement;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Events\InvoicePaid;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Models\Invoice;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\PlatformSettings;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The single write path for installing, licensing and changing optional apps. */
final class AppStoreManager
{
    public const int INVOICE_DAYS = 3;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly PlanEntitlementChecker $planEntitlements,
        private readonly InvoiceIssuer $invoices,
        private readonly AuditLogger $audit,
        private readonly PlatformSettings $settings,
    ) {}

    public function catalogApp(string $moduleKey): CatalogApp
    {
        $manifest = $this->modules->find($moduleKey);

        abort_if($manifest === null || $manifest->core, 404);

        /** @var CatalogApp $app */
        $app = CatalogApp::query()
            ->where('module_key', $moduleKey)
            ->where('is_published', true)
            ->with('currentVersion')
            ->firstOrFail();

        abort_if($app->currentVersion === null, 404);

        return $app;
    }

    public function isIncludedInPlan(CatalogApp $app): bool
    {
        return $this->planEntitlements->includedByPlan($this->context->require(), $app->module_key);
    }

    public function installFree(CatalogApp $app, string $offerKey): AppInstallation
    {
        $offer = $this->offer($app, $offerKey);

        if ($offer->type !== AppBillingType::Free) {
            throw ValidationException::withMessages(['offer' => __('Choose a free offer to install this app without payment.')]);
        }

        return DB::transaction(fn (): AppInstallation => $this->installLicensed(
            $app,
            EntitlementSource::Free,
            null,
            null,
        ));
    }

    /** Start a paid app trial or issue the invoice that must be verified before activation. */
    public function purchase(CatalogApp $app, string $offerKey): ?Invoice
    {
        $offer = $this->offer($app, $offerKey);

        if ($offer->type === AppBillingType::Free || ! $offer->type->isSelfServe()) {
            throw ValidationException::withMessages(['offer' => __('This app offer cannot be purchased online.')]);
        }

        return DB::transaction(function () use ($app, $offer): ?Invoice {
            $this->guardDependencies($app);
            $this->guardNotLicensed($app);

            Invoice::query()
                ->open()
                ->where('app_id', $app->getKey())
                ->lockForUpdate()
                ->get()
                ->each($this->invoices->void(...));

            $installation = $this->installation($app, lock: true) ?? new AppInstallation;
            $fresh = ! $installation->exists || $installation->status === InstallationStatus::Uninstalled;

            if (! $installation->exists) {
                $installation->settings = $this->defaultSettings($app);
            }

            $installation->fill([
                'app_id' => $app->getKey(),
                'app_version_id' => $app->current_version_id,
                'status' => InstallationStatus::PendingPayment,
                'deactivation_reason' => null,
                'installed_at' => $fresh ? null : $installation->installed_at,
                'uninstalled_at' => null,
            ]);
            $installation->save();

            if ($fresh) {
                $this->lifecycle($app)->installing($installation);
            }

            /** @var SubscriptionItem $item */
            $item = SubscriptionItem::query()->create([
                'app_id' => $app->getKey(),
                'installation_id' => $installation->getKey(),
                'offer_key' => $offer->key,
                'billing_type' => $offer->type,
                'status' => $offer->trialDays > 0 ? SubscriptionItemStatus::Trialing : SubscriptionItemStatus::Pending,
                'currency' => $offer->currency,
                'amount' => $offer->amount ?? 0,
                'quantity' => 1,
                'trial_ends_at' => $offer->trialDays > 0 ? Carbon::now()->addDays($offer->trialDays) : null,
            ]);

            if ($offer->trialDays > 0) {
                $this->completeInstallation($installation, $app, $fresh);
                $this->grant($installation, $app, EntitlementSource::Trial, $item, $item->trial_ends_at);
                $this->audit->record('app_store.trial_started', $installation, [
                    'app' => $app->module_key,
                    'offer' => $offer->key,
                    'trial_ends_at' => $item->trial_ends_at?->toIso8601String(),
                ]);

                return null;
            }

            $now = Carbon::now();
            $invoice = $this->invoices->issueApp(
                $item,
                $app,
                InvoiceKind::AppPurchase,
                $now,
                $now->copy()->addDays(self::INVOICE_DAYS),
            );

            $this->audit->record('app_store.purchase_started', $installation, [
                'app' => $app->module_key,
                'offer' => $offer->key,
                'invoice' => $invoice->number,
            ]);

            return $invoice;
        });
    }

    /** Fulfil a verified app invoice. Called only by InvoiceSettlement. */
    public function settle(Invoice $invoice, ?CarbonInterface $paidAt = null): bool
    {
        return DB::transaction(function () use ($invoice, $paidAt): bool {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $invoice->isOpen()) {
                return false;
            }

            if ($invoice->subscription_item_id === null || $invoice->app_id === null) {
                throw ValidationException::withMessages(['invoice' => __('This app invoice is incomplete and cannot be settled.')]);
            }

            /** @var SubscriptionItem $item */
            $item = SubscriptionItem::query()->with(['app', 'installation'])->lockForUpdate()->findOrFail($invoice->subscription_item_id);

            // A successful payment is retained as unapplied when its compiled
            // module is no longer available; never execute stale app code.
            if (! $this->modules->has($item->app->module_key)) {
                return false;
            }

            $previousStatus = $item->status;
            $paidAt = Carbon::instance($paidAt ?? Carbon::now());
            $start = $paidAt;

            if ($invoice->kind === InvoiceKind::AppRenewal && $invoice->period_start !== null) {
                $scheduledEnd = $item->billing_type->addPeriodTo($invoice->period_start);
                $start = $scheduledEnd !== null && $scheduledEnd->lessThanOrEqualTo($paidAt)
                    ? $paidAt
                    : $invoice->period_start;
            }

            $end = $item->billing_type->addPeriodTo($start);

            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => $paidAt]);
            $this->audit->record('billing.invoice_paid', $invoice, [
                'total_amount' => $invoice->total_amount,
                'currency' => $invoice->currency,
                'app' => $item->app->module_key,
            ]);
            InvoicePaid::dispatch($invoice);

            $item->fill([
                'status' => SubscriptionItemStatus::Active,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'ended_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
            ])->save();

            $installation = $item->installation;
            $fresh = $installation->installed_at === null;

            if ($previousStatus === SubscriptionItemStatus::Pending
                || $fresh
                || $installation->deactivation_reason === InstallationDeactivationReason::LicenceExpired) {
                $this->completeInstallation($installation, $item->app, $fresh);
            }

            $this->grant($installation, $item->app, EntitlementSource::Purchase, $item, $end);

            $this->audit->record('app_store.purchase_activated', $installation, [
                'app' => $item->app->module_key,
                'offer' => $item->offer_key,
                'invoice' => $invoice->number,
                'valid_until' => $end?->toIso8601String(),
            ]);

            return true;
        });
    }

    public function activate(CatalogApp $app): AppInstallation
    {
        return DB::transaction(function () use ($app): AppInstallation {
            $this->guardDependencies($app);
            $installation = $this->installation($app, lock: true);

            if ($installation === null || $installation->status === InstallationStatus::Uninstalled) {
                throw ValidationException::withMessages(['app' => __('Install this app before activating it.')]);
            }

            $licensed = $this->hasDirectLicence($app)
                || $this->entitlements->allows($this->context->require(), $app->module_key);

            if (! $licensed) {
                throw ValidationException::withMessages(['app' => __('This workspace does not have a valid licence for the app.')]);
            }

            if ($installation->status !== InstallationStatus::Active) {
                $lifecycle = $this->lifecycle($app);
                $lifecycle->activating($installation);
                $installation->update([
                    'status' => InstallationStatus::Active,
                    'app_version_id' => $app->current_version_id,
                    'deactivation_reason' => null,
                    'activated_at' => Carbon::now(),
                    'deactivated_at' => null,
                ]);
                $lifecycle->activated($installation);
                $this->audit->record('app_store.app_activated', $installation, ['app' => $app->module_key]);
                AppActivated::dispatch($installation->load('app'));
                $this->forgetEntitlements();
            }

            return $installation;
        });
    }

    public function deactivate(CatalogApp $app): AppInstallation
    {
        return DB::transaction(function () use ($app): AppInstallation {
            $this->guardNoDependents($app);
            $installation = $this->installation($app, lock: true);

            if ($installation === null || $installation->status !== InstallationStatus::Active) {
                throw ValidationException::withMessages(['app' => __('This app is not active.')]);
            }

            $lifecycle = $this->lifecycle($app);
            $lifecycle->deactivating($installation);
            $installation->update([
                'status' => InstallationStatus::Inactive,
                'deactivation_reason' => InstallationDeactivationReason::Manual,
                'deactivated_at' => Carbon::now(),
            ]);
            $lifecycle->deactivated($installation);
            $this->audit->record('app_store.app_deactivated', $installation, ['app' => $app->module_key]);
            AppDeactivated::dispatch($installation->load('app'));
            $this->forgetEntitlements();

            return $installation;
        });
    }

    public function uninstall(CatalogApp $app): AppInstallation
    {
        return DB::transaction(function () use ($app): AppInstallation {
            $this->guardNoDependents($app);
            $installation = $this->installation($app, lock: true);

            if ($installation === null || $installation->status === InstallationStatus::Uninstalled) {
                throw ValidationException::withMessages(['app' => __('This app is not installed.')]);
            }

            $lifecycle = $this->lifecycle($app);
            $lifecycle->uninstalling($installation);
            $now = Carbon::now();
            $installation->update([
                'status' => InstallationStatus::Uninstalled,
                'deactivation_reason' => null,
                'uninstalled_at' => $now,
                'deactivated_at' => $now,
            ]);

            // Uninstalling disables executable access but retains a purchased,
            // lifetime, trial, or free licence so the app can be reinstalled.
            SubscriptionItem::query()
                ->where('app_id', $app->getKey())
                ->where('status', SubscriptionItemStatus::Pending)
                ->update([
                    'status' => SubscriptionItemStatus::Canceled,
                    'cancel_at_period_end' => true,
                    'canceled_at' => $now,
                    'ended_at' => $now,
                ]);
            SubscriptionItem::query()
                ->where('app_id', $app->getKey())
                ->whereIn('status', [SubscriptionItemStatus::Trialing, SubscriptionItemStatus::Active, SubscriptionItemStatus::PastDue])
                ->update(['cancel_at_period_end' => true, 'canceled_at' => $now]);
            Invoice::query()
                ->open()
                ->where('app_id', $app->getKey())
                ->lockForUpdate()
                ->get()
                ->each($this->invoices->void(...));
            $lifecycle->uninstalled($installation);
            $this->audit->record('app_store.app_uninstalled', $installation, [
                'app' => $app->module_key,
                'data_retained' => true,
            ]);
            AppUninstalled::dispatch($installation->load('app'));
            $this->forgetEntitlements();

            return $installation;
        });
    }

    /** Re-enable an uninstalled app while an existing direct or plan licence is valid. */
    public function reinstall(CatalogApp $app): AppInstallation
    {
        return DB::transaction(function () use ($app): AppInstallation {
            $this->guardDependencies($app);
            $installation = $this->installation($app, lock: true);

            if ($installation === null || $installation->status !== InstallationStatus::Uninstalled) {
                throw ValidationException::withMessages(['app' => __('This app is not available to reinstall.')]);
            }

            $tenant = $this->context->require();

            if (! $this->hasDirectLicence($app) && ! $this->planEntitlements->includedByPlan($tenant, $app->module_key)) {
                throw ValidationException::withMessages(['app' => __('This workspace does not have a valid licence for the app.')]);
            }

            $this->lifecycle($app)->installing($installation);
            $installation->update([
                'status' => InstallationStatus::Inactive,
                'deactivation_reason' => null,
                'installed_at' => null,
                'uninstalled_at' => null,
            ]);
            $this->completeInstallation($installation, $app, fresh: true);
            $this->audit->record('app_store.app_reinstalled', $installation, [
                'app' => $app->module_key,
                'data_retained' => true,
            ]);

            return $installation;
        });
    }

    public function upgrade(CatalogApp $app): AppInstallation
    {
        return DB::transaction(function () use ($app): AppInstallation {
            $this->guardDependencies($app);
            $installation = $this->installation($app, lock: true);

            if ($installation === null
                || $installation->installed_at === null
                || in_array($installation->status, [InstallationStatus::PendingPayment, InstallationStatus::Uninstalled], true)) {
                throw ValidationException::withMessages(['app' => __('Install this app before updating it.')]);
            }

            if ($installation->app_version_id === $app->current_version_id) {
                return $installation;
            }

            $fromVersion = (string) ($installation->version()->value('version') ?? 'unknown');
            $toVersion = (string) $app->currentVersion->version;
            $installation->update(['app_version_id' => $app->current_version_id]);
            $this->lifecycle($app)->upgraded($installation, $fromVersion);
            $this->audit->record('app_store.app_upgraded', $installation, [
                'app' => $app->module_key,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
            ]);
            AppUpgraded::dispatch($installation->load(['app', 'version']), $fromVersion, $toVersion);

            return $installation;
        });
    }

    public function cancel(SubscriptionItem $item): SubscriptionItem
    {
        return DB::transaction(function () use ($item): SubscriptionItem {
            /** @var SubscriptionItem $item */
            $item = SubscriptionItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if (! $item->billing_type->isRecurring()) {
                throw ValidationException::withMessages(['subscription' => __('A one-time app licence does not renew.')]);
            }

            if (! $item->status->grantsLicense()) {
                throw ValidationException::withMessages(['subscription' => __('This app subscription is not active.')]);
            }

            $wasPastDue = $item->status === SubscriptionItemStatus::PastDue;
            $item->update(['cancel_at_period_end' => true, 'canceled_at' => Carbon::now()]);
            Invoice::query()
                ->open()
                ->where('subscription_item_id', $item->getKey())
                ->lockForUpdate()
                ->get()
                ->each($this->invoices->void(...));
            $this->audit->record('app_store.subscription_canceled', $item, ['app' => $item->app()->value('module_key')]);

            if ($wasPastDue) {
                return $this->expire($item, canceled: true);
            }

            return $item;
        });
    }

    public function resume(SubscriptionItem $item): SubscriptionItem
    {
        if (! $item->status->grantsLicense() || ! $item->cancel_at_period_end) {
            throw ValidationException::withMessages(['subscription' => __('This app subscription is not set to end.')]);
        }

        $item->update(['cancel_at_period_end' => false, 'canceled_at' => null]);
        $this->audit->record('app_store.subscription_resumed', $item, ['app' => $item->app()->value('module_key')]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateSettings(CatalogApp $app, array $input): AppInstallation
    {
        return DB::transaction(function () use ($app, $input): AppInstallation {
            $installation = $this->installation($app, lock: true);

            if ($installation === null
                || $installation->installed_at === null
                || $installation->status === InstallationStatus::Uninstalled) {
                throw ValidationException::withMessages(['app' => __('Install this app before changing its settings.')]);
            }

            $definitions = collect($this->modules->get($app->module_key)->settings)->keyBy('key');
            $allowedKeys = $definitions->keys()->all();
            $stored = $installation->settings ?? [];
            $rules = [
                'settings' => [
                    'required',
                    'array',
                    $allowedKeys === [] ? 'size:0' : 'array:'.implode(',', $allowedKeys),
                ],
            ];

            foreach ($definitions as $definition) {
                $hasValue = array_key_exists($definition->key, $stored)
                    && $stored[$definition->key] !== null
                    && $stored[$definition->key] !== '';
                $rules['settings.'.$definition->key] = $definition->validationRules($hasValue || $definition->default !== null);
            }

            /** @var array{settings: array<string, mixed>} $validated */
            $validated = Validator::make(['settings' => $input], $rules)->validate();
            $next = $stored;
            $changed = [];
            $secretKeys = [];

            foreach ($definitions as $definition) {
                if (! array_key_exists($definition->key, $validated['settings'])) {
                    continue;
                }

                $value = $validated['settings'][$definition->key];

                // A blank secret means "keep the current secret". Secret values
                // are never copied into validation errors, audit data or props.
                if ($definition->type === AppSettingType::Secret && ($value === null || $value === '')) {
                    continue;
                }

                if (($next[$definition->key] ?? null) !== $value) {
                    $changed[] = $definition->key;

                    if ($definition->type === AppSettingType::Secret) {
                        $secretKeys[] = $definition->key;
                    }
                }

                $next[$definition->key] = $value;
            }

            $installation->update(['settings' => $next]);
            $this->audit->record('app_store.settings_updated', $installation, [
                'app' => $app->module_key,
                'changed_keys' => $changed,
                'secret_keys' => $secretKeys,
            ]);

            return $installation->refresh();
        });
    }

    /** Keep access during grace while recording that renewal payment is late. */
    public function markPastDue(SubscriptionItem $item, CarbonInterface $boundary): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $boundary): SubscriptionItem {
            /** @var SubscriptionItem $item */
            $item = SubscriptionItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($item->status === SubscriptionItemStatus::PastDue) {
                return $item;
            }

            $graceEndsAt = Carbon::instance($boundary)->addDays($this->settings->graceDays());
            $item->update([
                'status' => SubscriptionItemStatus::PastDue,
                'current_period_end' => $boundary,
                'grace_ends_at' => $graceEndsAt,
            ]);
            AppEntitlement::query()
                ->where('subscription_item_id', $item->getKey())
                ->where('status', EntitlementStatus::Active)
                ->update(['valid_until' => $graceEndsAt]);
            $this->audit->record('app_store.subscription_past_due', $item, [
                'grace_ends_at' => $graceEndsAt->toIso8601String(),
            ]);
            $this->forgetEntitlements();

            return $item;
        });
    }

    /** End a licence without deleting the app's business records. */
    public function expire(SubscriptionItem $item, bool $canceled = false): SubscriptionItem
    {
        return DB::transaction(function () use ($item, $canceled): SubscriptionItem {
            /** @var SubscriptionItem $item */
            $item = SubscriptionItem::query()->with(['app', 'installation'])->lockForUpdate()->findOrFail($item->getKey());

            if (in_array($item->status, [SubscriptionItemStatus::Canceled, SubscriptionItemStatus::Expired], true)) {
                return $item;
            }

            $now = Carbon::now();
            $item->update([
                'status' => $canceled ? SubscriptionItemStatus::Canceled : SubscriptionItemStatus::Expired,
                'ended_at' => $now,
                'grace_ends_at' => null,
            ]);
            AppEntitlement::query()
                ->where('subscription_item_id', $item->getKey())
                ->where('status', EntitlementStatus::Active)
                ->update([
                    'status' => EntitlementStatus::Expired,
                    'valid_until' => $now,
                ]);

            $installation = $item->installation;

            if ($installation->status === InstallationStatus::Active) {
                $lifecycle = $this->lifecycle($item->app);
                $lifecycle->deactivating($installation);
                $installation->update([
                    'status' => InstallationStatus::Inactive,
                    'deactivation_reason' => InstallationDeactivationReason::LicenceExpired,
                    'deactivated_at' => $now,
                ]);
                $lifecycle->deactivated($installation);
                AppDeactivated::dispatch($installation->load('app'));
            }

            $this->audit->record(
                $canceled ? 'app_store.subscription_ended' : 'app_store.subscription_expired',
                $item,
                ['data_retained' => true],
            );
            $this->forgetEntitlements();

            return $item;
        });
    }

    private function installLicensed(
        CatalogApp $app,
        EntitlementSource $source,
        ?SubscriptionItem $item,
        ?CarbonInterface $validUntil,
    ): AppInstallation {
        $this->guardDependencies($app);
        $this->guardNotLicensed($app);
        $installation = $this->installation($app, lock: true) ?? new AppInstallation;
        $fresh = ! $installation->exists || $installation->status === InstallationStatus::Uninstalled;

        if (! $installation->exists) {
            $installation->settings = $this->defaultSettings($app);
        }

        $installation->fill([
            'app_id' => $app->getKey(),
            'app_version_id' => $app->current_version_id,
            'status' => InstallationStatus::Inactive,
            'deactivation_reason' => null,
            'uninstalled_at' => null,
        ])->save();

        if ($fresh) {
            $this->lifecycle($app)->installing($installation);
        }

        $this->completeInstallation($installation, $app, $fresh);
        $this->grant($installation, $app, $source, $item, $validUntil);
        $this->audit->record('app_store.app_installed', $installation, [
            'app' => $app->module_key,
            'source' => $source->value,
        ]);

        return $installation;
    }

    private function completeInstallation(AppInstallation $installation, CatalogApp $app, bool $fresh): void
    {
        $lifecycle = $this->lifecycle($app);
        $now = Carbon::now();

        if ($fresh) {
            $installation->update(['installed_at' => $now]);
            $lifecycle->installed($installation);
            AppInstalled::dispatch($installation->load('app'));
        }

        $lifecycle->activating($installation);
        $installation->update([
            'status' => InstallationStatus::Active,
            'app_version_id' => $app->current_version_id,
            'deactivation_reason' => null,
            'activated_at' => $now,
            'deactivated_at' => null,
        ]);
        $lifecycle->activated($installation);
        AppActivated::dispatch($installation->load('app'));
        $this->forgetEntitlements();
    }

    private function grant(
        AppInstallation $installation,
        CatalogApp $app,
        EntitlementSource $source,
        ?SubscriptionItem $item,
        ?CarbonInterface $validUntil,
    ): AppEntitlement {
        $now = Carbon::now();
        AppEntitlement::query()
            ->where('app_id', $app->getKey())
            ->where('status', EntitlementStatus::Active)
            ->update(['status' => EntitlementStatus::Revoked, 'revoked_at' => $now]);

        /** @var AppEntitlement $entitlement */
        $entitlement = AppEntitlement::query()->create([
            'app_id' => $app->getKey(),
            'installation_id' => $installation->getKey(),
            'subscription_item_id' => $item?->getKey(),
            'status' => EntitlementStatus::Active,
            'source' => $source,
            'license_id' => (string) Str::ulid(),
            'valid_from' => $now,
            'valid_until' => $validUntil,
        ]);
        $this->forgetEntitlements();

        return $entitlement;
    }

    private function guardDependencies(CatalogApp $app): void
    {
        $tenant = $this->context->require();
        $manifest = $this->modules->get($app->module_key);
        $missing = array_values(array_filter(
            $manifest->dependencies,
            fn (string $dependency): bool => $dependency !== 'core' && ! $this->entitlements->allows($tenant, $dependency),
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'dependencies' => __('Activate the required apps first: :apps.', ['apps' => implode(', ', $missing)]),
            ]);
        }
    }

    private function guardNoDependents(CatalogApp $app): void
    {
        $tenant = $this->context->require();
        $dependents = [];

        foreach ($this->modules->all() as $manifest) {
            if (in_array($app->module_key, $manifest->dependencies, true) && $this->entitlements->allows($tenant, $manifest->key)) {
                $dependents[] = $manifest->name;
            }
        }

        if ($dependents !== []) {
            throw ValidationException::withMessages([
                'dependents' => __('Deactivate dependent apps first: :apps.', ['apps' => implode(', ', $dependents)]),
            ]);
        }
    }

    private function guardNotLicensed(CatalogApp $app): void
    {
        if ($this->hasDirectLicence($app)) {
            throw ValidationException::withMessages(['app' => __('This workspace already has a valid licence for the app.')]);
        }

        if ($this->entitlements->allows($this->context->require(), $app->module_key)) {
            throw ValidationException::withMessages(['app' => __('This app is already included in the workspace plan.')]);
        }

        if (SubscriptionItem::query()->where('app_id', $app->getKey())->where('status', SubscriptionItemStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['app' => __('This app already has an invoice waiting for payment.')]);
        }
    }

    private function installation(CatalogApp $app, bool $lock = false): ?AppInstallation
    {
        $query = AppInstallation::query()->where('app_id', $app->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function offer(CatalogApp $app, string $key): AppOffer
    {
        $manifest = $this->modules->get($app->module_key);

        foreach ($manifest->offers as $offer) {
            if ($offer->key === $key && $offer->public) {
                return $offer;
            }
        }

        throw ValidationException::withMessages(['offer' => __('This app offer is not available.')]);
    }

    private function hasDirectLicence(CatalogApp $app): bool
    {
        return AppEntitlement::query()
            ->where('app_id', $app->getKey())
            ->where('status', EntitlementStatus::Active)
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->exists();
    }

    /** @return array<string, string|int|bool> */
    private function defaultSettings(CatalogApp $app): array
    {
        $defaults = [];

        foreach ($this->modules->get($app->module_key)->settings as $definition) {
            if ($definition->default !== null) {
                $defaults[$definition->key] = $definition->default;
            }
        }

        return $defaults;
    }

    private function lifecycle(CatalogApp $app): ModuleLifecycle
    {
        $class = $this->modules->get($app->module_key)->lifecycle;

        return $class === null ? new NullModuleLifecycle : app($class);
    }

    private function forgetEntitlements(): void
    {
        $this->planEntitlements->forget();
    }
}
