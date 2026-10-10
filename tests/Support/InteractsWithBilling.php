<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Billing\BillingCycle;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\PaymentProcessor;
use App\Core\Billing\SubscriptionManager;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\Fixtures\TestGateway;
use Tests\TestCase;

/**
 * Building blocks for tests about plans, subscriptions, invoices and payments.
 *
 * Billing records are central data scoped to their tenant, so every helper
 * that touches one names its tenant and runs inside that tenant's context.
 * Move time with `$this->travel(8)->days()` / `$this->travelTo(...)` and then
 * call `runBillingCycle()`: the cycle is what makes time matter.
 *
 * @mixin TestCase
 */
trait InteractsWithBilling
{
    /**
     * A plan. By default: paid (BDT 1,500.00 a month, 15,000.00 a year),
     * public, active, no trial. Amounts are minor units.
     *
     * @param  array<string, mixed>  $attributes  Any `plans` column: trial_days, monthly_amount, modules, limits, is_public, ...
     */
    protected function createPlan(array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes);
    }

    /**
     * A plan that costs nothing.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createFreePlan(array $attributes = []): Plan
    {
        return Plan::factory()->free()->create($attributes);
    }

    /**
     * Put a workspace that has no subscription yet on a plan, the way sign-up does.
     */
    protected function subscribe(
        Tenant $tenant,
        ?Plan $plan = null,
        BillingInterval $interval = BillingInterval::Monthly,
        bool $withTrial = true,
    ): Subscription {
        $plan ??= $this->createPlan();

        return $this->inTenant($tenant, fn (): Subscription => app(SubscriptionManager::class)->start($plan, $interval, $withTrial));
    }

    /**
     * A workspace on a paid plan whose first period has been paid for: the
     * state most tests about renewals, upgrades and cancellation start from.
     */
    protected function subscribeAndPay(
        Tenant $tenant,
        ?Plan $plan = null,
        BillingInterval $interval = BillingInterval::Monthly,
    ): Subscription {
        $this->subscribe($tenant, $plan, $interval, withTrial: false);
        $this->payOpenInvoice($tenant);

        return $this->subscriptionOf($tenant);
    }

    /**
     * The workspace's subscription as it is in the database now, with its plan.
     */
    protected function subscriptionOf(Tenant $tenant): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::forTenant($tenant)->with(['plan', 'pendingPlan'])->firstOrFail();

        return $subscription;
    }

    /**
     * Every invoice of the workspace, oldest first.
     *
     * @return Collection<int, Invoice>
     */
    protected function invoicesOf(Tenant $tenant): Collection
    {
        return Invoice::forTenant($tenant)->orderBy('id')->get();
    }

    protected function openInvoiceOf(Tenant $tenant): ?Invoice
    {
        return Invoice::forTenant($tenant)->open()->latest('id')->first();
    }

    /**
     * Every payment of the workspace, oldest first.
     *
     * @return Collection<int, Payment>
     */
    protected function paymentsOf(Tenant $tenant): Collection
    {
        return Payment::forTenant($tenant)->orderBy('id')->get();
    }

    /**
     * Let time act on the workspace's subscription, as the hourly `billing:run` does.
     */
    protected function runBillingCycle(Tenant $tenant): void
    {
        $this->inTenant($tenant, function (): void {
            app(BillingCycle::class)->run();
        });
    }

    /**
     * Pay the workspace's open invoice the way an operator records an offline
     * payment. Returns the payment.
     */
    protected function payOpenInvoice(Tenant $tenant, string $method = 'bank_transfer'): Payment
    {
        $invoice = $this->openInvoiceOf($tenant);

        if ($invoice === null) {
            $this->fail("Workspace [{$tenant->slug}] has no open invoice to pay.");
        }

        $operator = $this->createPlatformUser();

        return $this->inTenant($tenant, fn (): Payment => app(PaymentProcessor::class)->recordOffline($invoice, $operator, $method));
    }

    /**
     * Register a gateway that never leaves the process and whose answers the
     * test controls. Call it before the request that should see it.
     */
    protected function useTestGateway(): TestGateway
    {
        $registry = $this->app->make(GatewayRegistry::class);

        $gateway = $registry->find('testpay');

        if (! $gateway instanceof TestGateway) {
            $gateway = new TestGateway;
            $registry->register($gateway);
        }

        return $gateway;
    }
}
