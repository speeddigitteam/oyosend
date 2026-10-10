<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Models\UsageRecord;
use App\Core\Billing\SubscriptionManager;
use App\Core\Billing\UsageMeter;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/*
 * The workspace's own billing pages: what `billing` and
 * `billing/invoices/{invoice}` show in each state a subscription can be in,
 * who may look and who may change, and what choosing a plan, cancelling and
 * resuming answer over HTTP. The rules themselves are covered by
 * SubscriptionLifecycleTest; paying an invoice by PaymentFlowTest.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);

    $this->free = $this->createFreePlan(['key' => 'free', 'name' => 'Free', 'sort_order' => 1]);
    $this->starter = $this->createPlan([
        'key' => 'starter',
        'name' => 'Starter',
        'monthly_amount' => 150000,
        'yearly_amount' => 1500000,
        'limits' => ['core.members' => 5],
        'sort_order' => 2,
    ]);
    $this->business = $this->createPlan([
        'key' => 'business',
        'name' => 'Business',
        'monthly_amount' => 450000,
        'yearly_amount' => 4500000,
        'sort_order' => 3,
    ]);

    // The billing page as the owner sees it: its props.
    $this->billing = fn (string $prop): mixed => $this->actingAsOwner($this->acme)
        ->get('http://acme.localhost/billing')
        ->assertOk()
        ->inertiaProps($prop);

    $this->choose = fn (Plan $plan, string $interval = 'monthly') => $this->actingAsOwner($this->acme)
        ->post('http://acme.localhost/billing/subscription', ['plan_id' => $plan->id, 'interval' => $interval]);
});

describe('the billing page', function () {
    it('shows a workspace without a subscription the plans it can choose', function () {
        $this->createPlan(['name' => 'Negotiated', 'is_public' => false]);
        $this->createPlan(['name' => 'Retired', 'is_active' => false]);

        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/billing')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Core/Pages/Billing/Index')
                    ->where('subscription', null)
                    ->where('openInvoice', null)
                    ->where('invoices', [])
                    ->where('gateways', [])
                    ->where('offlineInstructions', null)
                    ->where('returnedPayment', null)
                    ->where('canManage', true);

                $plans = collect($page->toArray()['props']['plans']);

                // Only what a workspace may choose for itself, in the order the operator gave.
                expect($plans->pluck('name')->all())->toBe(['Free', 'Starter', 'Business'])
                    ->and($plans->pluck('current')->unique()->all())->toBe([false])
                    ->and($plans->firstWhere('key', 'starter'))->toMatchArray([
                        'id' => $this->starter->id,
                        'currency' => 'BDT',
                        'monthlyAmount' => 150000,
                        'yearlyAmount' => 1500000,
                        'trialDays' => 0,
                        'limits' => ['core.members' => 5],
                        'isFree' => false,
                        'isPublic' => true,
                        'isActive' => true,
                    ])
                    ->and($plans->firstWhere('key', 'free'))->toMatchArray([
                        'monthlyAmount' => 0,
                        'yearlyAmount' => null,
                        'isFree' => true,
                    ]);
            });
    });

    it('shows a trial and when it ends', function () {
        $pro = $this->createPlan(['key' => 'pro', 'name' => 'Pro', 'trial_days' => 14, 'monthly_amount' => 200000]);
        $this->subscribe($this->acme, $pro);

        expect(($this->billing)('subscription'))->toMatchArray([
            'status' => 'trialing',
            'plan' => ['id' => $pro->id, 'key' => 'pro', 'name' => 'Pro'],
            'interval' => 'monthly',
            'amount' => 200000,
            'currency' => 'BDT',
            'isFree' => false,
            'locked' => false,
            'trialEndsAt' => '2026-03-24T09:00:00+00:00',
            'periodEnd' => null,
            'cancelAtPeriodEnd' => false,
            'pending' => null,
        ])
            ->and(($this->billing)('openInvoice'))->toBeNull()
            ->and(collect(($this->billing)('plans'))->where('current', true)->pluck('key')->all())->toBe(['pro']);
    });

    it('shows an active subscription with the period that was paid for and its invoice', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        expect(($this->billing)('subscription'))->toMatchArray([
            'status' => 'active',
            'locked' => false,
            'periodStart' => '2026-03-10T09:00:00+00:00',
            'periodEnd' => '2026-04-10T09:00:00+00:00',
            'graceEndsAt' => null,
            'endedAt' => null,
        ])
            ->and(($this->billing)('openInvoice'))->toBeNull();

        $invoices = ($this->billing)('invoices');

        expect($invoices)->toHaveCount(1)
            ->and($invoices[0])->toMatchArray([
                'kind' => 'subscription',
                'status' => 'paid',
                'overdue' => false,
                'currency' => 'BDT',
                'subtotalAmount' => 150000,
                'creditAmount' => 0,
                'totalAmount' => 150000,
                'interval' => 'monthly',
                'periodStart' => '2026-03-10T09:00:00+00:00',
                'periodEnd' => '2026-04-10T09:00:00+00:00',
            ])
            ->and($invoices[0]['number'])->toMatch('/^INV-2026-\d{6}$/')
            ->and($invoices[0]['paidAt'])->not->toBeNull();
    });

    it('shows what is owed when a payment is overdue', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        // The renewal was issued a week ahead and the period ended two days ago.
        $this->travelTo(Carbon::parse('2026-04-04 09:00:00'));
        $this->runBillingCycle($this->acme);
        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));
        $this->runBillingCycle($this->acme);

        $open = $this->openInvoiceOf($this->acme);

        expect(($this->billing)('subscription'))->toMatchArray([
            'status' => 'past_due',
            'locked' => false,
            'graceEndsAt' => '2026-04-17T09:00:00+00:00',
        ])
            ->and(($this->billing)('openInvoice'))->toMatchArray([
                'id' => $open->id,
                'number' => $open->number,
                'kind' => 'renewal',
                'status' => 'open',
                'overdue' => true,
                'totalAmount' => 150000,
                'dueAt' => '2026-04-10T09:00:00+00:00',
            ])
            // Newest first.
            ->and(array_column(($this->billing)('invoices'), 'status'))->toBe(['open', 'paid']);
    });

    it('shows a move to a cheaper plan that waits for the paid period to end', function () {
        $this->subscribeAndPay($this->acme, $this->business);

        ($this->choose)($this->starter, 'yearly')->assertRedirect('http://acme.localhost/billing');

        expect(($this->billing)('subscription'))->toMatchArray([
            'status' => 'active',
            'plan' => ['id' => $this->business->id, 'key' => 'business', 'name' => 'Business'],
            'pending' => ['plan' => 'Starter', 'interval' => 'yearly'],
        ]);
    });

    it('shows a subscription that is set to end', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());

        expect(($this->billing)('subscription'))->toMatchArray([
            'status' => 'active',
            'cancelAtPeriodEnd' => true,
            'periodEnd' => '2026-04-10T09:00:00+00:00',
            'locked' => false,
        ]);
    });

    it('stays reachable when the workspace is locked, with the invoice that brings it back', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        expect(($this->billing)('subscription'))->toMatchArray([
            'status' => 'expired',
            'locked' => true,
            'endedAt' => '2026-03-18T09:00:00+00:00',
            'graceEndsAt' => null,
        ])
            ->and(($this->billing)('openInvoice'))->toMatchArray(['status' => 'open', 'overdue' => true, 'totalAmount' => 150000]);

        // The rest of the workspace is closed to the same person.
        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/team/members')
            ->assertRedirect('http://acme.localhost/billing');
    });

    it('lists the plan the workspace is on even when it is not sold to others', function () {
        $negotiated = $this->createPlan(['key' => 'negotiated', 'name' => 'Negotiated', 'is_public' => false, 'sort_order' => 9]);
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->grant($negotiated, BillingInterval::Monthly));

        $plans = collect(($this->billing)('plans'));

        expect($plans->pluck('key')->all())->toBe(['free', 'starter', 'business', 'negotiated'])
            ->and($plans->firstWhere('key', 'negotiated'))->toMatchArray(['current' => true, 'isPublic' => false])
            ->and($plans->where('current', true))->toHaveCount(1);

        // Archived afterwards: still listed for the workspace that is on it.
        $negotiated->update(['is_active' => false]);

        expect(collect(($this->billing)('plans'))->firstWhere('key', 'negotiated'))->toMatchArray(['current' => true, 'isActive' => false]);
    });

    it('reports what the workspace uses of each limit its plan sets', function () {
        // Seats: the owner, one more member, and an invitation that can still be accepted.
        $this->addMember($this->acme, Role::EMPLOYEE);
        $this->createInvitation($this->acme, 'new@acme.test');

        expect(collect(($this->billing)('usage'))->firstWhere('key', 'core.members'))
            ->toBe(['key' => 'core.members', 'kind' => 'gauge', 'limit' => null, 'used' => 3]);

        $this->subscribeAndPay($this->acme, $this->starter);

        expect(collect(($this->billing)('usage'))->firstWhere('key', 'core.members'))
            ->toBe(['key' => 'core.members', 'kind' => 'gauge', 'limit' => 5, 'used' => 3]);
    });

    it('never shows another workspace\'s subscription or invoices', function () {
        $globex = $this->createTenant('globex');
        $this->subscribeAndPay($globex, $this->business);

        expect(($this->billing)('subscription'))->toBeNull()
            ->and(($this->billing)('invoices'))->toBe([])
            ->and(collect(($this->billing)('plans'))->pluck('current')->unique()->all())->toBe([false]);
    });
});

describe('who may see and change billing', function () {
    beforeEach(function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $this->invoice = $this->openInvoiceOf($this->acme);

        $this->viewer = $this->addMember($this->acme, $this->createRole($this->acme, 'Accounts', ['core.billing.view']));
        $this->manager = $this->addMember(
            $this->acme,
            $this->createRole($this->acme, 'Finance', ['core.billing.view', 'core.billing.manage']),
        );
        $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);
    });

    it('lets a role with core.billing.view read the pages, without the controls', function () {
        $this->actingAsMember($this->acme, $this->viewer);

        $this->get('http://acme.localhost/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                ->where('subscription.status', 'past_due')
                ->where('openInvoice.id', $this->invoice->id));

        $this->get("http://acme.localhost/billing/invoices/{$this->invoice->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoice.number', $this->invoice->number)
                ->where('canPay', false));
    });

    it('refuses every change to a role that only holds core.billing.view', function (string $path, array $data) {
        $this->useTestGateway();

        $path = str_replace('{invoice}', $this->invoice->id, $path);
        $data = array_map(fn (string $value): string => $value === '{plan}' ? $this->business->id : $value, $data);

        $this->actingAsMember($this->acme, $this->viewer)
            ->post('http://acme.localhost/'.$path, $data)
            ->assertForbidden();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->plan->key)->toBe('starter')
            ->and($this->paymentsOf($this->acme))->toBeEmpty()
            ->and($this->invoicesOf($this->acme)->sole()->status)->toBe(InvoiceStatus::Open);
    })->with([
        'change plan' => ['billing/subscription', ['plan_id' => '{plan}', 'interval' => 'monthly']],
        'cancel' => ['billing/subscription/cancel', []],
        'resume' => ['billing/subscription/resume', []],
        'pay' => ['billing/invoices/{invoice}/pay', ['gateway' => 'testpay']],
    ]);

    it('lets a role with core.billing.manage change the plan and pay', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->get('http://acme.localhost/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true));

        $this->get("http://acme.localhost/billing/invoices/{$this->invoice->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canPay', true));

        $this->post('http://acme.localhost/billing/subscription', ['plan_id' => $this->business->id, 'interval' => 'monthly'])
            ->assertRedirect();

        expect($this->openInvoiceOf($this->acme)->total_amount)->toBe(450000);

        // Recorded under the person who asked for it.
        $entry = $this->auditTrail($this->acme, 'billing.invoice_issued')->last();

        expect($entry->actor_id)->toBe($this->manager->id);
    });

    it('keeps billing from members whose role does not mention it', function () {
        $this->actingAsMember($this->acme, $this->employee);

        $this->get('http://acme.localhost/billing')->assertForbidden();
        $this->get("http://acme.localhost/billing/invoices/{$this->invoice->id}")->assertForbidden();
        $this->post('http://acme.localhost/billing/subscription/cancel')->assertForbidden();
    });

    it('sends a signed-out visitor to the sign-in page', function () {
        $this->get('http://acme.localhost/billing')->assertRedirect('http://acme.localhost/login');
        $this->get("http://acme.localhost/billing/invoices/{$this->invoice->id}")->assertRedirect('http://acme.localhost/login');
        $this->post('http://acme.localhost/billing/subscription/cancel')->assertRedirect('http://acme.localhost/login');
    });
});

describe('choosing a plan', function () {
    it('leads to the invoice when the plan starts with a payment', function () {
        $response = ($this->choose)($this->starter);

        $invoice = $this->openInvoiceOf($this->acme);

        $response->assertRedirect("http://acme.localhost/billing/invoices/{$invoice->id}")
            ->assertSessionHasNoErrors();

        expect($invoice->kind)->toBe(InvoiceKind::Subscription)
            ->and($invoice->total_amount)->toBe(150000)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    });

    it('bills the interval that was asked for', function () {
        ($this->choose)($this->starter, 'yearly');

        expect($this->openInvoiceOf($this->acme)->total_amount)->toBe(1500000)
            ->and($this->subscriptionOf($this->acme)->interval)->toBe(BillingInterval::Yearly);
    });

    it('goes back to billing when a free plan applies at once', function () {
        ($this->choose)($this->free)->assertRedirect('http://acme.localhost/billing')->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('free')
            ->and($this->invoicesOf($this->acme))->toBeEmpty();
    });

    it('goes back to billing when the plan changes during a trial', function () {
        $this->subscribe($this->acme, $this->createPlan(['trial_days' => 14]));

        ($this->choose)($this->business)->assertRedirect('http://acme.localhost/billing');

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->plan->key)->toBe('business')
            ->and($this->invoicesOf($this->acme))->toBeEmpty();
    });

    it('goes back to billing when a cheaper plan is scheduled for the end of the paid period', function () {
        $this->subscribeAndPay($this->acme, $this->business);

        ($this->choose)($this->starter)->assertRedirect('http://acme.localhost/billing');

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->plan->key)->toBe('business')
            ->and($subscription->pendingPlan->key)->toBe('starter')
            ->and($this->openInvoiceOf($this->acme))->toBeNull();
    });

    it('leads to an invoice with credit when moving up inside a paid period', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->travelTo(Carbon::parse('2026-03-21 09:00:00'));

        $response = ($this->choose)($this->business);

        $invoice = $this->openInvoiceOf($this->acme);

        $response->assertRedirect("http://acme.localhost/billing/invoices/{$invoice->id}");

        expect($invoice->kind)->toBe(InvoiceKind::Change)
            ->and($invoice->credit_amount)->toBe(intdiv(150000 * 20, 31))
            // Nothing changes until it is paid.
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');
    });

    it('cannot choose a plan that is not offered to workspaces', function (array $attributes) {
        $this->subscribeAndPay($this->acme, $this->starter);
        $plan = $this->createPlan(['monthly_amount' => 900000, ...$attributes]);

        ($this->choose)($plan)->assertNotFound();

        expect($this->subscriptionOf($this->acme)->plan->key)->toBe('starter')
            ->and($this->invoicesOf($this->acme))->toHaveCount(1);
    })->with([
        'not public' => [['is_public' => false]],
        'archived' => [['is_active' => false]],
        'neither' => [['is_public' => false, 'is_active' => false]],
    ]);

    it('lets a workspace on a plan that is not public change how that plan is billed', function () {
        $negotiated = $this->createPlan(['key' => 'negotiated', 'is_public' => false]);
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->grant($negotiated, BillingInterval::Monthly));

        ($this->choose)($negotiated, 'yearly')->assertRedirect('http://acme.localhost/billing')->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->pending_plan_id)->toBe($negotiated->id)
            ->and($subscription->pending_interval)->toBe(BillingInterval::Yearly);
    });

    it('refuses an interval the plan is not sold for', function () {
        $monthlyOnly = $this->createPlan(['yearly_amount' => null]);

        ($this->choose)($monthlyOnly, 'yearly')->assertSessionHasErrors('interval');

        expect($this->invoicesOf($this->acme))->toBeEmpty()
            ->and(Subscription::forTenant($this->acme)->exists())->toBeFalse();
    });

    it('refuses the plan and interval the workspace is already on', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        ($this->choose)($this->starter)->assertSessionHasErrors('plan');

        expect($this->invoicesOf($this->acme))->toHaveCount(1);
    });

    it('validates what it is sent', function (array $data, string $field) {
        $data = array_map(fn (mixed $value): mixed => $value === '{plan}' ? $this->starter->id : $value, $data);

        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription', $data)
            ->assertSessionHasErrors($field);

        expect(Subscription::forTenant($this->acme)->exists())->toBeFalse();
    })->with([
        'no plan' => [['interval' => 'monthly'], 'plan_id'],
        'a plan id of the wrong shape' => [['plan_id' => 'starter', 'interval' => 'monthly'], 'plan_id'],
        'a list of plans' => [['plan_id' => ['a', 'b'], 'interval' => 'monthly'], 'plan_id'],
        'no interval' => [['plan_id' => '{plan}'], 'interval'],
        'an unknown interval' => [['plan_id' => '{plan}', 'interval' => 'weekly'], 'interval'],
    ]);

    it('answers 404 for a plan that does not exist', function () {
        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription', ['plan_id' => str_repeat('0', 26), 'interval' => 'monthly'])
            ->assertNotFound();
    });
});

describe('cancelling and resuming', function () {
    it('sets a paid subscription to end with its period, and takes that back', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->actingAsOwner($this->acme);

        $this->post('http://acme.localhost/billing/subscription/cancel')
            ->assertRedirect('http://acme.localhost/billing')
            ->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->cancel_at_period_end)->toBeTrue()
            ->and($this->auditTrail($this->acme, 'billing.subscription_canceled'))->toHaveCount(1);

        $this->post('http://acme.localhost/billing/subscription/resume')
            ->assertRedirect('http://acme.localhost/billing')
            ->assertSessionHasNoErrors();

        expect($this->subscriptionOf($this->acme)->cancel_at_period_end)->toBeFalse()
            ->and($this->auditTrail($this->acme, 'billing.subscription_resumed'))->toHaveCount(1);
    });

    it('ends a subscription at once when a payment is overdue, and withdraws the invoice', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription/cancel')
            ->assertRedirect('http://acme.localhost/billing');

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
            ->and($subscription->isLocked())->toBeTrue()
            ->and($this->invoicesOf($this->acme)->sole()->status)->toBe(InvoiceStatus::Void);
    });

    it('has nothing to cancel on a free plan, or without a subscription', function () {
        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription/cancel')
            ->assertSessionHasErrors('subscription');

        $this->subscribe($this->acme, $this->free);

        $this->post('http://acme.localhost/billing/subscription/cancel')->assertSessionHasErrors('subscription');

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    });

    it('cannot resume a subscription that is not set to end', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription/resume')
            ->assertSessionHasErrors('subscription');
    });

    it('cannot resume a subscription that has already ended', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());

        $this->travelTo(Carbon::parse('2026-04-10 10:00:00'));
        $this->runBillingCycle($this->acme);

        $this->actingAsOwner($this->acme)
            ->post('http://acme.localhost/billing/subscription/resume')
            ->assertSessionHasErrors('subscription');

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Canceled);
    });
});

describe('the invoice page', function () {
    beforeEach(function () {
        config(['platform.billing.issuer' => [
            'name' => 'Oyosend Ltd',
            'address' => "House 1, Road 2\nDhaka 1207",
            'email' => 'billing@oyosend.test',
            'tax_id' => 'BIN-000123',
        ]]);

        $this->acme->update([
            'legal_name' => 'Acme Trading Limited',
            'email' => 'accounts@acme.test',
            'phone' => '+8801700000000',
            'address' => '12 Lake Road',
            'city' => 'Dhaka',
            'postal_code' => '1212',
            'country' => 'BD',
            'tax_id' => 'TIN-42',
        ]);
    });

    it('shows an unpaid invoice as a document, with how it can be paid', function () {
        config(['platform.billing.offline_instructions' => "Bank: Example Bank\nAccount: 0001"]);

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $invoice = $this->openInvoiceOf($this->acme);

        $this->actingAsOwner($this->acme)
            ->get("http://acme.localhost/billing/invoices/{$invoice->id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($invoice): void {
                $page->component('Core/Pages/Billing/Invoice')
                    ->where('invoice.id', $invoice->id)
                    ->where('invoice.number', $invoice->number)
                    ->where('invoice.kind', 'subscription')
                    ->where('invoice.status', 'open')
                    ->where('invoice.currency', 'BDT')
                    ->where('invoice.subtotalAmount', 150000)
                    ->where('invoice.creditAmount', 0)
                    ->where('invoice.totalAmount', 150000)
                    ->where('invoice.issuedAt', '2026-03-10T09:00:00+00:00')
                    ->where('invoice.dueAt', '2026-03-10T09:00:00+00:00')
                    ->where('invoice.periodStart', '2026-03-10T09:00:00+00:00')
                    ->where('invoice.periodEnd', '2026-04-10T09:00:00+00:00')
                    ->where('invoice.paidAt', null)
                    ->where('invoice.voidedAt', null)
                    ->where('payments', [])
                    // No gateway has credentials in the test environment: the page says so.
                    ->where('gateways', [])
                    ->where('offlineInstructions', "Bank: Example Bank\nAccount: 0001")
                    ->where('canPay', true);

                $document = $page->toArray()['props']['invoice'];

                // JSON columns: MySQL returns the keys in its own order.
                expect($document['lines'])->toEqual([['type' => 'plan', 'plan' => 'Starter', 'interval' => 'monthly', 'amount' => 150000]])
                    // Who it is made out to, as the workspace stood on the day of issue.
                    ->and($document['billedTo'])->toEqual([
                        'name' => 'Acme Trading Limited',
                        'email' => 'accounts@acme.test',
                        'phone' => '+8801700000000',
                        'address' => '12 Lake Road',
                        'city' => 'Dhaka',
                        'postal_code' => '1212',
                        'country' => 'BD',
                        'tax_id' => 'TIN-42',
                    ])
                    ->and($document['issuer'])->toEqual([
                        'name' => 'Oyosend Ltd',
                        'address' => "House 1, Road 2\nDhaka 1207",
                        'email' => 'billing@oyosend.test',
                        'tax_id' => 'BIN-000123',
                    ]);
            });
    });

    it('keeps who an invoice was made out to when the workspace changes afterwards', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $invoice = $this->openInvoiceOf($this->acme);

        $this->acme->update(['legal_name' => 'Renamed Limited', 'city' => 'Chattogram']);

        $billedTo = $this->actingAsOwner($this->acme)
            ->get("http://acme.localhost/billing/invoices/{$invoice->id}")
            ->inertiaProps('invoice.billedTo');

        expect($billedTo['name'])->toBe('Acme Trading Limited')
            ->and($billedTo['city'])->toBe('Dhaka');
    });

    it('shows a paid invoice with its payment and nothing left to pay', function () {
        config(['platform.billing.offline_instructions' => 'Pay at the office.']);

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $invoice = $this->openInvoiceOf($this->acme);
        $payment = $this->payOpenInvoice($this->acme, 'cheque');

        $this->actingAsOwner($this->acme)
            ->get("http://acme.localhost/billing/invoices/{$invoice->id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($payment): void {
                $page->where('invoice.status', 'paid')
                    ->where('invoice.overdue', false)
                    ->where('invoice.paidAt', '2026-03-10T09:00:00+00:00')
                    ->where('gateways', [])
                    ->where('offlineInstructions', null)
                    ->where('canPay', false)
                    ->has('payments', 1);

                $shown = $page->toArray()['props']['payments'][0];

                expect($shown)->toMatchArray([
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'gateway' => 'offline',
                    'method' => 'cheque',
                    'status' => 'succeeded',
                    'amount' => 150000,
                    'currency' => 'BDT',
                    'failureReason' => null,
                ])
                    // What the operator noted, and who recorded it, stay in the panel.
                    ->and(array_intersect(array_keys($shown), ['note', 'bankReference', 'details', 'recorded_by', 'recordedBy']))->toBe([]);
            });
    });

    it('shows the credit on an invoice for a change of plan', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->travelTo(Carbon::parse('2026-03-21 09:00:00'));
        ($this->choose)($this->business);

        $invoice = $this->openInvoiceOf($this->acme);
        $credit = intdiv(150000 * 20, 31);

        $document = $this->actingAsOwner($this->acme)
            ->get("http://acme.localhost/billing/invoices/{$invoice->id}")
            ->assertOk()
            ->inertiaProps('invoice');

        expect($document)->toMatchArray([
            'kind' => 'change',
            'subtotalAmount' => 450000,
            'creditAmount' => $credit,
            'totalAmount' => 450000 - $credit,
            'dueAt' => '2026-03-24T09:00:00+00:00',
        ])
            ->and($document['lines'])->toEqual([
                ['type' => 'plan', 'plan' => 'Business', 'interval' => 'monthly', 'amount' => 450000],
                ['type' => 'credit', 'amount' => -$credit],
            ]);
    });

    it('answers 404 for an invoice that does not exist', function () {
        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/billing/invoices/'.str_repeat('0', 26))
            ->assertNotFound();
    });

    it('does not serve an invoice to another workspace', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $invoice = $this->openInvoiceOf($this->acme);

        $this->assertNotFoundFromOtherTenant('GET', "/billing/invoices/{$invoice->id}", $this->acme);
    });

    it('does not let another workspace start paying an invoice', function () {
        $this->useTestGateway();

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $invoice = $this->openInvoiceOf($this->acme);

        $globex = $this->createTenant('globex');

        $this->startNewBrowserSession()
            ->actingAsOwner($globex)
            ->post("http://globex.localhost/billing/invoices/{$invoice->id}/pay", ['gateway' => 'testpay'])
            ->assertNotFound();

        expect($this->paymentsOf($this->acme))->toBeEmpty()
            ->and($this->paymentsOf($globex))->toBeEmpty();

        $this->assertNotFoundFromOtherTenant('POST', "/billing/invoices/{$invoice->id}/pay", $this->acme, ['gateway' => 'testpay'], $globex);
    });
});

describe('tenant isolation of billing records', function () {
    it('isolates subscriptions between tenants', function () {
        $this->assertTenantIsolated(
            Subscription::class,
            fn (Tenant $tenant): Subscription => $this->subscribe($tenant, $this->starter, withTrial: false),
        );
    });

    it('isolates invoices between tenants', function () {
        $this->assertTenantIsolated(Invoice::class, function (Tenant $tenant): Invoice {
            $this->subscribe($tenant, $this->starter, withTrial: false);

            return Invoice::query()->sole();
        });
    });

    it('isolates payments between tenants', function () {
        $this->assertTenantIsolated(Payment::class, function (Tenant $tenant): Payment {
            $this->subscribe($tenant, $this->starter, withTrial: false);

            return $this->payOpenInvoice($tenant);
        });
    });

    it('isolates usage records between tenants', function () {
        $this->assertTenantIsolated(UsageRecord::class, function (Tenant $tenant): UsageRecord {
            // The same idempotency key in both workspaces: it is unique per workspace, not platform-wide.
            expect(app(UsageMeter::class)->record('sms.messages', 3, 'sms:message-1'))->toBeTrue();

            return UsageRecord::query()->sole();
        });
    });
});
