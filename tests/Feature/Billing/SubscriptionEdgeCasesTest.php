<?php

declare(strict_types=1);

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Events\InvoicePaid;
use App\Core\Billing\Events\SubscriptionUpdated;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Mail\InvoiceIssuedMail;
use App\Core\Billing\Mail\PaymentReceivedMail;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\PaymentProcessor;
use App\Core\Billing\SubscriptionManager;
use App\Core\Billing\Support\InvoiceNumbers;
use App\Core\Billing\Support\PlanChange;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Settings\UpdateWorkspaceProfile;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * What SubscriptionLifecycleTest leaves out: the operator's tools (grant,
 * extend a trial), changes of plan from the awkward states (past due, free,
 * no subscription), cancelling late, and the bookkeeping around all of it -
 * one open invoice at a time, invoice numbers, the billed party, events.
 *
 * Every test starts on 10 March 2026, 09:00 UTC. Defaults: the next invoice
 * is issued 7 days before a period or a trial ends; 7 days of grace after it.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->acme = $this->createTenant('acme');
    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000]);
    $this->business = $this->createPlan(['key' => 'business', 'name' => 'Business', 'monthly_amount' => 450000, 'yearly_amount' => 4500000]);
    $this->pro = $this->createPlan(['key' => 'pro', 'name' => 'Pro', 'trial_days' => 14, 'monthly_amount' => 200000, 'yearly_amount' => 2000000]);
    $this->free = $this->createFreePlan(['key' => 'free', 'name' => 'Free']);

    /** Call the subscription manager as the workspace. */
    $this->billing = fn (Closure $callback): mixed => $this->inTenant(
        $this->acme,
        fn (): mixed => $callback(app(SubscriptionManager::class)),
    );

    $this->change = fn (Plan $plan, BillingInterval $interval = BillingInterval::Monthly): PlanChange => ($this->billing)(
        fn (SubscriptionManager $manager): PlanChange => $manager->changePlan($plan, $interval),
    );

    /** The fields a refusal is reported on. */
    $this->refusal = function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        $this->fail('The request was not refused.');
    };

    /** On Starter, the first month paid, the renewal unpaid for two days. */
    $this->goPastDue = function (?Plan $plan = null): Subscription {
        $this->subscribeAndPay($this->acme, $plan ?? $this->starter);
        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));
        $this->runBillingCycle($this->acme);

        return $this->subscriptionOf($this->acme);
    };

    $this->enterStage = function (string $stage): void {
        $cancel = fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());

        match ($stage) {
            'on trial, with its first invoice issued' => (function (): void {
                $this->subscribe($this->acme, $this->pro);
                $this->travel(8)->days();
                $this->runBillingCycle($this->acme);
            })(),
            'past due' => ($this->goPastDue)(),
            'expired' => (function (): void {
                $this->subscribe($this->acme, $this->starter, withTrial: false);
                $this->travel(8)->days();
                $this->runBillingCycle($this->acme);
            })(),
            'ended by cancellation' => (function () use ($cancel): void {
                $this->subscribeAndPay($this->acme, $this->starter);
                $cancel();
                $this->travelTo(Carbon::parse('2026-04-10 10:00:00'));
                $this->runBillingCycle($this->acme);
            })(),
            'set to end with the period' => (function () use ($cancel): void {
                $this->subscribeAndPay($this->acme, $this->starter);
                $cancel();
            })(),
            'with a cheaper plan scheduled' => (function (): void {
                $this->subscribeAndPay($this->acme, $this->business);
                ($this->change)($this->starter);
            })(),
            'with an upgrade waiting to be paid' => (function (): void {
                $this->subscribeAndPay($this->acme, $this->starter);
                $this->travel(5)->days();
                ($this->change)($this->business);
            })(),
            'on a free plan' => $this->subscribe($this->acme, $this->free),
        };
    };

    /*
     * Watched in every test of this file: a workspace never has two open
     * invoices, not even between two writes of one operation.
     */
    $this->overlappingInvoices = [];

    Invoice::saved(function (Invoice $invoice): void {
        $open = Invoice::forTenant($invoice->tenant_id)->open()->pluck('number')->all();

        if (count($open) > 1) {
            $this->overlappingInvoices[] = $open;
        }
    });
});

afterEach(function () {
    expect($this->overlappingInvoices)->toBe([], 'A workspace had more than one open invoice at a time.');
});

/*
 * Stages a workspace can be in when someone steps in. Each leaves `acme` with
 * a subscription; most with an invoice that is still open. Entered with
 * `($this->enterStage)($stage)`.
 */
dataset('subscription stages', [
    'on trial, with its first invoice issued',
    'past due',
    'expired',
    'ended by cancellation',
    'set to end with the period',
    'with a cheaper plan scheduled',
    'with an upgrade waiting to be paid',
    'on a free plan',
]);

describe('a plan granted by the operator', function () {
    it('puts a workspace that has no subscription on the plan at once, without an invoice', function () {
        $subscription = ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant($this->business, BillingInterval::Monthly));

        expect($subscription->tenant_id)->toBe($this->acme->id)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan_id)->toBe($this->business->id)
            ->and($subscription->interval)->toBe(BillingInterval::Monthly)
            ->and($subscription->amount)->toBe(450000)
            ->and($subscription->currency)->toBe('BDT')
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->isInPaidPeriod())->toBeTrue()
            ->and(Subscription::forTenant($this->acme)->count())->toBe(1)
            ->and($this->invoicesOf($this->acme))->toBeEmpty()
            ->and($this->paymentsOf($this->acme))->toBeEmpty();

        $entry = $this->auditTrail($this->acme, 'billing.plan_granted')->sole();

        expect($entry->subject_type)->toBe('subscription')
            ->and($entry->subject_id)->toBe($subscription->id)
            ->and($entry->subject_label)->toBe('Business')
            ->and($entry->properties)->toEqual([
                'plan' => 'business',
                'interval' => 'monthly',
                'period_end' => '2026-04-10T09:00:00+00:00',
            ]);

        Mail::assertNothingQueued();
    });

    it('can be any plan: hidden, archived, with a trial of its own', function () {
        $private = $this->createPlan([
            'key' => 'partner',
            'is_public' => false,
            'is_active' => false,
            'trial_days' => 30,
            'monthly_amount' => 990000,
            'yearly_amount' => null,
        ]);

        $subscription = ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant($private, BillingInterval::Monthly));

        // Given, not tried: no trial.
        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan_id)->toBe($private->id)
            ->and($subscription->amount)->toBe(990000)
            ->and($subscription->trial_ends_at)->toBeNull();
    });

    it('replaces whatever the workspace was on and withdraws what it was asked to pay', function (string $stage) {
        ($this->enterStage)($stage);

        $before = $this->invoicesOf($this->acme);
        $paid = $before->where('status', InvoiceStatus::Paid)->pluck('id')->all();

        $granted = ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant($this->business, BillingInterval::Yearly));
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->id)->toBe($granted->id)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->interval)->toBe(BillingInterval::Yearly)
            ->and($subscription->amount)->toBe(4500000)
            ->and($subscription->trial_ends_at)->toBeNull()
            ->and($subscription->current_period_start->equalTo(Carbon::now()))->toBeTrue()
            ->and($subscription->current_period_end->equalTo(Carbon::now()->addYear()))->toBeTrue()
            ->and($subscription->grace_ends_at)->toBeNull()
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->cancel_at_period_end)->toBeFalse()
            ->and($subscription->canceled_at)->toBeNull()
            ->and($subscription->pending_plan_id)->toBeNull()
            ->and($subscription->pending_interval)->toBeNull()
            ->and($subscription->grantsAccess())->toBeTrue();

        $after = $this->invoicesOf($this->acme);

        // Nothing is open any more; what was open is void, not deleted; what was paid stays paid.
        expect($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($after)->toHaveCount($before->count())
            ->and($after->where('status', InvoiceStatus::Paid)->pluck('id')->all())->toBe($paid)
            ->and($after->where('status', InvoiceStatus::Void)->count())
            ->toBe($before->where('status', '!==', InvoiceStatus::Paid)->count());

        // And time finds nothing to ask for until the granted period is nearly over.
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    })->with('subscription stages');

    it('runs until the day the operator names', function () {
        $subscription = ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant(
            $this->starter,
            BillingInterval::Monthly,
            Carbon::parse('2026-12-31 18:00:00'),
        ));

        expect($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($this->subscriptionOf($this->acme)->current_period_end->toDateTimeString())->toBe('2026-12-31 18:00:00')
            ->and($this->auditTrail($this->acme, 'billing.plan_granted')->sole()->properties['period_end'])->toBe('2026-12-31T18:00:00+00:00');

        // Nothing falls due in between ...
        $this->travelTo(Carbon::parse('2026-12-24 17:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->invoicesOf($this->acme))->toBeEmpty();

        // ... and a week before it runs out the workspace is asked to pay for the next period, at the plan's price.
        $this->travelTo(Carbon::parse('2026-12-24 18:00:00'));
        $this->runBillingCycle($this->acme);

        $invoice = $this->invoicesOf($this->acme)->sole();

        expect($invoice->kind)->toBe(InvoiceKind::Renewal)
            ->and($invoice->total_amount)->toBe(150000)
            ->and($invoice->period_start->toDateTimeString())->toBe('2026-12-31 18:00:00')
            ->and($invoice->period_end->toDateTimeString())->toBe('2027-01-31 18:00:00')
            ->and($invoice->due_at->toDateTimeString())->toBe('2026-12-31 18:00:00');
    });

    it('has no period when the plan is free, whatever end was named', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        $subscription = ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant(
            $this->free,
            BillingInterval::Monthly,
            Carbon::parse('2026-12-31 18:00:00'),
        ));

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->amount)->toBe(0)
            ->and($subscription->isFree())->toBeTrue()
            ->and($subscription->current_period_end)->toBeNull()
            ->and($subscription->isInPaidPeriod())->toBeFalse();

        $this->travel(400)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($this->invoicesOf($this->acme))->toHaveCount(1);
    });

    it('is refused for an interval the plan is not sold for, and then changes nothing', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant($this->free, BillingInterval::Yearly))))
            ->toBe(['interval']);

        expect($this->subscriptionOf($this->acme)->plan->key)->toBe('starter')
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->openInvoiceOf($this->acme))->not->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.plan_granted'))->toBeEmpty();
    });

    it('entitles the workspace to the plan\'s modules in the same breath', function () {
        $withCrm = $this->createPlan(['key' => 'crm-plan', 'modules' => ['crm']]);

        $entitled = $this->inTenant($this->acme, function () use ($withCrm): array {
            $checker = app(EntitlementChecker::class);
            $before = $checker->entitledModules($this->acme);

            app(SubscriptionManager::class)->grant($withCrm, BillingInterval::Monthly);

            return [$before, $checker->entitledModules($this->acme)];
        });

        expect($entitled)->toBe([['core'], ['core', 'crm']]);
    });
});

describe('a trial extended by the operator', function () {
    it('moves the end of a running trial, and the invoice follows it', function () {
        $this->subscribe($this->acme, $this->pro);
        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        $first = $this->openInvoiceOf($this->acme);

        expect($first->period_start->toDateTimeString())->toBe('2026-03-24 09:00:00');

        $subscription = ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-04-30 09:00:00')));

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($this->subscriptionOf($this->acme)->trial_ends_at->toDateTimeString())->toBe('2026-04-30 09:00:00')
            ->and($first->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($first->voided_at)->not->toBeNull()
            ->and($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.trial_extended')->sole()->properties)
            ->toEqual(['trial_ends_at' => '2026-04-30T09:00:00+00:00']);

        // The old end passes: nothing is due, nothing is asked for.
        $this->travelTo(Carbon::parse('2026-03-25 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Trialing)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();

        // A week before the new end the cycle asks again, for the period that starts there.
        $this->travelTo(Carbon::parse('2026-04-23 09:00:00'));
        $this->runBillingCycle($this->acme);

        $second = $this->openInvoiceOf($this->acme);

        expect($second->id)->not->toBe($first->id)
            ->and($second->number)->not->toBe($first->number)
            ->and($second->kind)->toBe(InvoiceKind::Subscription)
            ->and($second->total_amount)->toBe(200000)
            ->and($second->period_start->toDateTimeString())->toBe('2026-04-30 09:00:00')
            ->and($second->period_end->toDateTimeString())->toBe('2026-05-30 09:00:00')
            ->and($second->due_at->toDateTimeString())->toBe('2026-04-30 09:00:00')
            ->and($this->invoicesOf($this->acme))->toHaveCount(2);
    });

    it('takes back a cancellation of the trial', function () {
        $this->subscribe($this->acme, $this->pro);
        ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-04-30 09:00:00')));

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->cancel_at_period_end)->toBeFalse()
            ->and($subscription->canceled_at)->toBeNull();

        $this->travelTo(Carbon::parse('2026-04-30 10:00:00'));
        $this->runBillingCycle($this->acme);

        // Unpaid, so past due - not ended as a canceled trial would be.
        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    });

    it('puts a workspace whose trial ended unpaid back on trial', function () {
        $this->subscribe($this->acme, $this->pro);
        $this->travelTo(Carbon::parse('2026-03-24 10:00:00'));
        $this->runBillingCycle($this->acme);

        $owed = $this->openInvoiceOf($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($owed)->not->toBeNull();

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-04-07 09:00:00')));

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-04-07 09:00:00')
            ->and($subscription->grace_ends_at)->toBeNull()
            ->and($subscription->current_period_start)->toBeNull()
            ->and($subscription->current_period_end)->toBeNull()
            ->and($subscription->plan->key)->toBe('pro')
            ->and($owed->refresh()->status)->toBe(InvoiceStatus::Void);

        // The old grace period would have run out on 31 March. Nothing happens then
        // but the invoice for the new end of the trial, which is a week away.
        $this->travelTo(Carbon::parse('2026-03-31 10:00:00'));
        $this->runBillingCycle($this->acme);

        $next = $this->openInvoiceOf($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Trialing)
            ->and($next->period_start->toDateTimeString())->toBe('2026-04-07 09:00:00')
            ->and($next->due_at->toDateTimeString())->toBe('2026-04-07 09:00:00');

        // Unpaid again: past due again, with a grace period counted from the new end.
        $this->travelTo(Carbon::parse('2026-04-07 10:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-04-14 09:00:00')
            ->and($this->invoicesOf($this->acme))->toHaveCount(2);
    });

    it('unlocks a workspace that had expired', function () {
        $this->subscribe($this->acme, $this->pro);
        $this->travelTo(Carbon::parse('2026-04-05 09:00:00'));
        $this->runBillingCycle($this->acme);

        $owed = $this->openInvoiceOf($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Expired)
            ->and($owed)->not->toBeNull();

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-04-20 09:00:00')));

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->isLocked())->toBeFalse()
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-04-20 09:00:00')
            ->and($owed->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();

        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Trialing)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();

        // Paying the invoice of the new trial early starts the paid period where the trial ends.
        $this->travelTo(Carbon::parse('2026-04-14 09:00:00'));
        $this->runBillingCycle($this->acme);
        $this->payOpenInvoice($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-20 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-20 09:00:00');
    });

    it('gives a trial to a workspace whose paid plan ran out unpaid or was canceled', function (string $stage) {
        ($this->enterStage)($stage);

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::now()->addDays(10)));

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->plan->key)->toBe('starter')
            ->and($subscription->trial_ends_at->equalTo(Carbon::now()->addDays(10)))->toBeTrue()
            ->and($subscription->current_period_end)->toBeNull()
            ->and($subscription->grace_ends_at)->toBeNull()
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->canceled_at)->toBeNull()
            ->and($this->openInvoiceOf($this->acme))->toBeNull();
    })->with(['past due', 'expired', 'ended by cancellation']);

    it('is refused inside a period that has been paid for', function (string $stage) {
        ($this->enterStage)($stage);

        $before = $this->subscriptionOf($this->acme)->getAttributes();
        $open = $this->openInvoiceOf($this->acme)?->id;

        expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-06-01 09:00:00')))))
            ->toBe(['trial_ends_at']);

        expect($this->subscriptionOf($this->acme)->getAttributes())->toBe($before)
            // What the workspace was being asked to pay is still asked for.
            ->and($this->openInvoiceOf($this->acme)?->id)->toBe($open)
            ->and($this->auditTrail($this->acme, 'billing.trial_extended'))->toBeEmpty();
    })->with(['set to end with the period', 'with a cheaper plan scheduled', 'with an upgrade waiting to be paid']);

    it('is refused on a free plan, and for a workspace without a subscription', function () {
        $extend = fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-06-01 09:00:00')));

        expect(($this->refusal)($extend))->toBe(['subscription'])
            ->and(Subscription::forTenant($this->acme)->exists())->toBeFalse();

        $this->subscribe($this->acme, $this->free);

        expect(($this->refusal)($extend))->toBe(['trial_ends_at'])
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($this->subscriptionOf($this->acme)->trial_ends_at)->toBeNull();
    });
});

describe('changing plan while a payment is overdue', function () {
    beforeEach(function () {
        ($this->goPastDue)();

        $this->owed = $this->openInvoiceOf($this->acme);
    });

    it('starts from a renewal that is two days late', function () {
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-04-17 09:00:00')
            ->and($this->owed->kind)->toBe(InvoiceKind::Renewal)
            ->and($this->owed->total_amount)->toBe(150000);
    });

    it('replaces the invoice with one for the new plan, for the period that already began', function () {
        $change = ($this->change)($this->business);
        $invoice = $change->invoice;

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($this->owed->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($invoice->is($this->openInvoiceOf($this->acme)))->toBeTrue()
            ->and($invoice->kind)->toBe(InvoiceKind::Subscription)
            ->and($invoice->plan_id)->toBe($this->business->id)
            ->and($invoice->interval)->toBe(BillingInterval::Monthly)
            // Nothing is left of a period that is over: no credit.
            ->and($invoice->subtotal_amount)->toBe(450000)
            ->and($invoice->credit_amount)->toBe(0)
            ->and($invoice->total_amount)->toBe(450000)
            ->and($invoice->lines)->toEqual([['type' => 'plan', 'plan' => 'Business', 'interval' => 'monthly', 'amount' => 450000]])
            ->and($invoice->period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($invoice->period_end->toDateTimeString())->toBe('2026-05-10 09:00:00')
            // Owed now; and it is not an offer that lapses after three days.
            ->and($invoice->due_at->toDateTimeString())->toBe('2026-04-12 09:00:00');

        // Until it is paid the workspace stays where it was, with the same days of grace.
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->plan->key)->toBe('starter')
            ->and($subscription->amount)->toBe(150000)
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-04-17 09:00:00');

        // Three days on it is still there to pay (a lapsed offer would have been withdrawn).
        $this->travelTo(Carbon::parse('2026-04-16 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme)->id)->toBe($invoice->id);

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->amount)->toBe(450000)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-10 09:00:00')
            ->and($subscription->grace_ends_at)->toBeNull();
    });

    it('does the same for a cheaper plan: there is no paid period left to wait for', function () {
        $cheaper = $this->createPlan(['key' => 'lite', 'name' => 'Lite', 'monthly_amount' => 50000]);
        $change = ($this->change)($cheaper);

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($change->invoice->total_amount)->toBe(50000)
            ->and($change->invoice->period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($this->subscriptionOf($this->acme)->pending_plan_id)->toBeNull();
    });

    it('bills a year from the day the unpaid period began, when the interval changes', function () {
        $change = ($this->change)($this->starter, BillingInterval::Yearly);

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($change->invoice->total_amount)->toBe(1500000)
            ->and($change->invoice->interval)->toBe(BillingInterval::Yearly)
            ->and($change->invoice->period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($change->invoice->period_end->toDateTimeString())->toBe('2027-04-10 09:00:00');
    });

    it('refuses the plan the workspace is already on', function () {
        expect(($this->refusal)(fn () => ($this->change)($this->starter)))->toBe(['plan'])
            ->and($this->owed->refresh()->status)->toBe(InvoiceStatus::Open);
    });

    it('still expires when the grace period runs out, and then starts with the payment', function () {
        ($this->change)($this->business);

        $this->travelTo(Carbon::parse('2026-04-17 10:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Expired)
            ->and($subscription->plan->key)->toBe('starter')
            ->and($this->openInvoiceOf($this->acme)->plan_id)->toBe($this->business->id);

        $this->travelTo(Carbon::parse('2026-04-20 09:00:00'));
        $this->payOpenInvoice($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-20 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-20 09:00:00');
    });

    it('moves to a free plan at once and owes nothing', function () {
        expect(($this->change)($this->free)->outcome)->toBe(PlanChange::APPLIED);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('free')
            ->and($subscription->amount)->toBe(0)
            ->and($subscription->current_period_end)->toBeNull()
            ->and($subscription->grace_ends_at)->toBeNull()
            ->and($this->owed->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();

        $this->travel(30)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    });
});

describe('a past-due subscription whose invoice was withdrawn', function () {
    it('gets a new invoice for the same period from the cycle, once', function () {
        ($this->goPastDue)();

        $withdrawn = $this->openInvoiceOf($this->acme);

        $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($withdrawn));

        expect($this->openInvoiceOf($this->acme))->toBeNull();

        $this->travel(1)->days();
        $this->runBillingCycle($this->acme);
        $this->runBillingCycle($this->acme);

        $invoices = $this->invoicesOf($this->acme);
        $replacement = $this->openInvoiceOf($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($invoices)->toHaveCount(3)
            ->and($replacement->id)->not->toBe($withdrawn->id)
            ->and($replacement->kind)->toBe(InvoiceKind::Renewal)
            ->and($replacement->plan_id)->toBe($this->starter->id)
            ->and($replacement->total_amount)->toBe(150000)
            ->and($replacement->period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($replacement->period_end->toDateTimeString())->toBe('2026-05-10 09:00:00')
            ->and($replacement->due_at->toDateTimeString())->toBe('2026-04-10 09:00:00')
            // Withdrawing an invoice buys no time.
            ->and($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-04-17 09:00:00');

        $this->payOpenInvoice($this->acme);

        expect($this->subscriptionOf($this->acme)->current_period_end->toDateTimeString())->toBe('2026-05-10 09:00:00');
    });

    it('is asked for the cheaper plan that was scheduled before the payment fell due', function () {
        $this->subscribeAndPay($this->acme, $this->business);
        ($this->change)($this->starter);

        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));
        $this->runBillingCycle($this->acme);

        $first = $this->openInvoiceOf($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($first->plan_id)->toBe($this->starter->id);

        $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($first));
        $this->runBillingCycle($this->acme);

        $second = $this->openInvoiceOf($this->acme);

        expect($second->id)->not->toBe($first->id)
            ->and($second->plan_id)->toBe($this->starter->id)
            ->and($second->total_amount)->toBe(150000);
    });

    it('is not given one once it has expired', function () {
        ($this->goPastDue)();

        $this->travelTo(Carbon::parse('2026-04-18 09:00:00'));
        $this->runBillingCycle($this->acme);

        $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($this->openInvoiceOf($this->acme)));

        $this->travel(1)->days();
        $this->runBillingCycle($this->acme);

        // Ended: the workspace takes a plan again from its billing page.
        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Expired)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();
    });
});

describe('changing plan on a free plan', function () {
    beforeEach(fn () => $this->subscribe($this->acme, $this->free));

    it('starts the paid plan when its invoice is paid, with a period that begins at the payment', function () {
        $this->travel(20)->days();

        $change = ($this->change)($this->starter);
        $invoice = $change->invoice;

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($invoice->kind)->toBe(InvoiceKind::Change)
            ->and($invoice->total_amount)->toBe(150000)
            ->and($invoice->credit_amount)->toBe(0)
            ->and($invoice->lines)->toHaveCount(1)
            ->and($invoice->due_at->toDateTimeString())->toBe('2026-04-02 09:00:00')
            // Free until the money is in.
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('free')
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);

        $this->travelTo(Carbon::parse('2026-03-31 15:30:00'));
        $this->payOpenInvoice($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('starter')
            ->and($subscription->amount)->toBe(150000)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-31 15:30:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-30 15:30:00')
            ->and($subscription->isInPaidPeriod())->toBeTrue();
    });

    it('does not start a trial of the new plan', function () {
        $change = ($this->change)($this->pro);

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($this->subscriptionOf($this->acme)->trial_ends_at)->toBeNull();
    });

    it('stays free when the offer is not taken up, and never falls due', function () {
        ($this->change)($this->starter);

        $this->travelTo(Carbon::parse('2026-03-13 09:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($this->invoicesOf($this->acme)->sole()->status)->toBe(InvoiceStatus::Void)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('free');
    });

    it('replaces the offer when another plan is chosen instead', function () {
        $first = ($this->change)($this->starter)->invoice;
        $second = ($this->change)($this->business)->invoice;

        expect($first->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($second->total_amount)->toBe(450000)
            ->and($this->openInvoiceOf($this->acme)->id)->toBe($second->id);
    });

    it('moves between free plans at once and refuses the one it is on', function () {
        $community = $this->createFreePlan(['key' => 'community', 'name' => 'Community']);

        expect(($this->change)($community)->outcome)->toBe(PlanChange::APPLIED)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('community')
            ->and(($this->refusal)(fn () => ($this->change)($community)))->toBe(['plan'])
            ->and($this->invoicesOf($this->acme))->toBeEmpty();
    });
});

/*
 * The rule (SubscriptionManager::isUpgrade): a change is an upgrade when it
 * costs more per month than what the workspace pays now. An upgrade is
 * invoiced at once, with credit for what is left of the paid period; anything
 * else waits for the period to end.
 */
describe('switching between monthly and yearly', function () {
    it('waits for the end of the month when the year is cheaper per month, as a year usually is', function () {
        // 1,500.00 a month; 15,000.00 a year, which is 1,250.00 a month.
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->travel(10)->days();

        $change = ($this->change)($this->starter, BillingInterval::Yearly);
        $subscription = $this->subscriptionOf($this->acme);

        expect($change->outcome)->toBe(PlanChange::SCHEDULED)
            ->and($change->effectiveAt->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($change->invoice)->toBeNull()
            ->and($subscription->interval)->toBe(BillingInterval::Monthly)
            ->and($subscription->amount)->toBe(150000)
            ->and($subscription->pending_plan_id)->toBe($this->starter->id)
            ->and($subscription->pending_interval)->toBe(BillingInterval::Yearly)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();

        // The renewal is for a year.
        $this->travelTo(Carbon::parse('2026-04-03 09:00:00'));
        $this->runBillingCycle($this->acme);

        $invoice = $this->openInvoiceOf($this->acme);

        expect($invoice->kind)->toBe(InvoiceKind::Renewal)
            ->and($invoice->interval)->toBe(BillingInterval::Yearly)
            ->and($invoice->total_amount)->toBe(1500000)
            ->and($invoice->period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($invoice->period_end->toDateTimeString())->toBe('2027-04-10 09:00:00');

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->interval)->toBe(BillingInterval::Yearly)
            ->and($subscription->amount)->toBe(1500000)
            ->and($subscription->pending_plan_id)->toBeNull()
            ->and($subscription->pending_interval)->toBeNull()
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2027-04-10 09:00:00');
    });

    it('waits as well when a year costs exactly twelve months', function () {
        $flat = $this->createPlan(['key' => 'flat', 'monthly_amount' => 100000, 'yearly_amount' => 1200000]);

        $this->subscribeAndPay($this->acme, $flat);

        expect(($this->change)($flat, BillingInterval::Yearly)->outcome)->toBe(PlanChange::SCHEDULED);
    });

    it('is an upgrade, invoiced at once with credit, when the year costs more per month', function () {
        // 1,000.00 a month; 15,000.00 a year, which is 1,250.00 a month.
        $dearYear = $this->createPlan(['key' => 'dear-year', 'name' => 'Dear year', 'monthly_amount' => 100000, 'yearly_amount' => 1500000]);

        $this->subscribeAndPay($this->acme, $dearYear);
        // 31 days in the period; 20 of them are left.
        $this->travelTo(Carbon::parse('2026-03-21 09:00:00'));

        $change = ($this->change)($dearYear, BillingInterval::Yearly);

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($change->invoice->kind)->toBe(InvoiceKind::Change)
            ->and($change->invoice->interval)->toBe(BillingInterval::Yearly)
            ->and($change->invoice->subtotal_amount)->toBe(1500000)
            ->and($change->invoice->credit_amount)->toBe(intdiv(100000 * 20, 31))
            ->and($change->invoice->total_amount)->toBe(1500000 - intdiv(100000 * 20, 31))
            ->and($this->subscriptionOf($this->acme)->pending_plan_id)->toBeNull();
    });

    it('is an upgrade from a year to months of the same plan, because a month costs more per month', function () {
        $this->subscribeAndPay($this->acme, $this->starter, BillingInterval::Yearly);

        // One of the twelve months is used: 334 of 365 days are left, worth far more than one month.
        $this->travelTo(Carbon::parse('2026-04-10 09:00:00'));

        $change = ($this->change)($this->starter, BillingInterval::Monthly);

        expect($change->outcome)->toBe(PlanChange::APPLIED)
            ->and($this->subscriptionOf($this->acme)->interval)->toBe(BillingInterval::Monthly);
    });
});

describe('an upgrade whose credit covers the whole price', function () {
    it('is applied at once, with a paid invoice of nothing and no request for money', function () {
        $this->subscribeAndPay($this->acme, $this->starter, BillingInterval::Yearly);

        Mail::fake();

        // The same day: the whole year (15,000.00) is unused; a month of Business costs 4,500.00.
        $change = ($this->change)($this->business, BillingInterval::Monthly);

        $invoice = $this->invoicesOf($this->acme)->last();
        $subscription = $this->subscriptionOf($this->acme);

        expect($change->outcome)->toBe(PlanChange::APPLIED)
            ->and($change->invoice)->toBeNull()
            ->and($invoice->kind)->toBe(InvoiceKind::Change)
            ->and($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->paid_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($invoice->subtotal_amount)->toBe(450000)
            // Credit never exceeds the price: an invoice is never negative.
            ->and($invoice->credit_amount)->toBe(450000)
            ->and($invoice->total_amount)->toBe(0)
            ->and($invoice->lines)->toEqual([
                ['type' => 'plan', 'plan' => 'Business', 'interval' => 'monthly', 'amount' => 450000],
                ['type' => 'credit', 'amount' => -450000],
            ])
            ->and($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->interval)->toBe(BillingInterval::Monthly)
            ->and($subscription->amount)->toBe(450000)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            // No money changed hands for it.
            ->and($this->paymentsOf($this->acme))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(2);

        Mail::assertNotQueued(InvoiceIssuedMail::class);
        Mail::assertNotQueued(PaymentReceivedMail::class);

        // The next period is billed at the full price.
        $this->travelTo(Carbon::parse('2026-04-03 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme)->total_amount)->toBe(450000);
    });

    it('cannot be paid, because there is nothing to pay', function () {
        $this->subscribeAndPay($this->acme, $this->starter, BillingInterval::Yearly);
        ($this->change)($this->business, BillingInterval::Monthly);

        $invoice = $this->invoicesOf($this->acme)->last();
        $operator = $this->createPlatformUser();

        expect(($this->refusal)(fn () => $this->inTenant(
            $this->acme,
            fn () => app(PaymentProcessor::class)->recordOffline($invoice, $operator, 'cash'),
        )))->toBe(['invoice']);
    });
});

describe('plans in different currencies', function () {
    it('gives no credit for a period paid in another currency', function () {
        $dollars = $this->createPlan(['key' => 'global', 'name' => 'Global', 'currency' => 'USD', 'monthly_amount' => 990000, 'yearly_amount' => null]);

        $this->subscribeAndPay($this->acme, $this->starter);
        // 20 of 31 days of a BDT period are left.
        $this->travelTo(Carbon::parse('2026-03-21 09:00:00'));

        $change = ($this->change)($dollars);
        $invoice = $change->invoice;

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($invoice->kind)->toBe(InvoiceKind::Change)
            ->and($invoice->currency)->toBe('USD')
            ->and($invoice->subtotal_amount)->toBe(990000)
            ->and($invoice->credit_amount)->toBe(0)
            ->and($invoice->total_amount)->toBe(990000)
            ->and($invoice->lines)->toEqual([['type' => 'plan', 'plan' => 'Global', 'interval' => 'monthly', 'amount' => 990000]]);

        $payment = $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($payment->currency)->toBe('USD')
            ->and($payment->amount)->toBe(990000)
            ->and($subscription->currency)->toBe('USD')
            ->and($subscription->amount)->toBe(990000)
            ->and($subscription->plan->key)->toBe('global');
    });

    it('credits the unused part when the currency is the same', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->travelTo(Carbon::parse('2026-03-21 09:00:00'));

        expect(($this->change)($this->business)->invoice->credit_amount)->toBe(intdiv(150000 * 20, 31));
    });
});

describe('changing plan without a subscription', function () {
    it('takes a paid plan the way sign-up does without a trial: invoiced at once, with the grace period to pay', function () {
        $change = ($this->change)($this->pro);
        $subscription = $this->subscriptionOf($this->acme);

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($change->invoice->is($this->openInvoiceOf($this->acme)))->toBeTrue()
            ->and($change->invoice->kind)->toBe(InvoiceKind::Subscription)
            ->and($change->invoice->total_amount)->toBe(200000)
            ->and($change->invoice->period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            // The plan has a trial; a plan chosen from the billing page does not start one.
            ->and($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->trial_ends_at)->toBeNull()
            ->and($subscription->plan->key)->toBe('pro')
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-03-17 09:00:00');
    });

    it('takes a free plan at once', function () {
        $change = ($this->change)($this->free);

        expect($change->outcome)->toBe(PlanChange::APPLIED)
            ->and($change->invoice)->toBeNull()
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($this->invoicesOf($this->acme))->toBeEmpty();
    });
});

describe('a plan that is not sold for the interval asked for', function () {
    it('cannot be started', function () {
        $monthlyOnly = $this->createPlan(['key' => 'monthly-only', 'yearly_amount' => null]);

        foreach ([$this->free, $monthlyOnly] as $plan) {
            expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->start($plan, BillingInterval::Yearly))))
                ->toBe(['interval']);
        }

        expect(Subscription::forTenant($this->acme)->exists())->toBeFalse()
            ->and($this->invoicesOf($this->acme))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'billing.subscription_started'))->toBeEmpty();
    });

    it('cannot be changed to, with or without a subscription, and nothing is touched by the attempt', function () {
        $yearlyOnly = $this->createPlan(['key' => 'yearly-only', 'monthly_amount' => null, 'yearly_amount' => 9000000]);

        expect(($this->refusal)(fn () => ($this->change)($yearlyOnly, BillingInterval::Monthly)))->toBe(['interval'])
            ->and(Subscription::forTenant($this->acme)->exists())->toBeFalse();

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $open = $this->openInvoiceOf($this->acme);

        expect(($this->refusal)(fn () => ($this->change)($yearlyOnly, BillingInterval::Monthly)))->toBe(['interval'])
            ->and(($this->refusal)(fn () => ($this->change)($this->free, BillingInterval::Yearly)))->toBe(['interval'])
            // The invoice that was open is still the one to pay.
            ->and($open->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->invoicesOf($this->acme))->toHaveCount(1)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');

        // The interval it is sold for works.
        expect(($this->change)($yearlyOnly, BillingInterval::Yearly)->outcome)->toBe(PlanChange::AWAITING_PAYMENT);
    });
});

describe('starting twice', function () {
    it('is refused: a workspace has one subscription', function (bool $withTrial) {
        $this->subscribe($this->acme, $this->pro, withTrial: $withTrial);

        $invoices = $this->invoicesOf($this->acme)->count();

        foreach ([$this->pro, $this->business, $this->free] as $plan) {
            expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->start($plan, BillingInterval::Monthly))))
                ->toBe(['plan']);
        }

        expect(Subscription::forTenant($this->acme)->count())->toBe(1)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('pro')
            ->and($this->invoicesOf($this->acme))->toHaveCount($invoices)
            ->and($this->auditTrail($this->acme, 'billing.subscription_started'))->toHaveCount(1);
    })->with(['on trial' => [true], 'invoiced at once' => [false]]);

    it('does not stop another workspace from starting', function () {
        $globex = $this->createTenant('globex');

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $subscription = $this->subscribe($globex, $this->starter, withTrial: false);

        expect($subscription->tenant_id)->toBe($globex->id)
            ->and($this->subscriptionOf($this->acme)->id)->not->toBe($subscription->id);
    });
});

describe('cancelling late', function () {
    it('ends a past-due subscription at once and withdraws its invoice', function () {
        ($this->goPastDue)();

        $owed = $this->openInvoiceOf($this->acme);

        $canceled = ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());
        $subscription = $this->subscriptionOf($this->acme);

        expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
            ->and($subscription->status)->toBe(SubscriptionStatus::Canceled)
            ->and($subscription->isLocked())->toBeTrue()
            ->and($subscription->ended_at->toDateTimeString())->toBe('2026-04-12 09:00:00')
            ->and($subscription->canceled_at->toDateTimeString())->toBe('2026-04-12 09:00:00')
            ->and($subscription->grace_ends_at)->toBeNull()
            // Not "at the end of the period": there is no paid period left.
            ->and($subscription->cancel_at_period_end)->toBeFalse()
            ->and($owed->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.subscription_canceled')->sole()->properties)->toEqual(['status' => 'canceled']);

        // Time has nothing more to do with it.
        $this->travel(30)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Canceled)
            ->and($this->invoicesOf($this->acme))->toHaveCount(2);
    });

    it('ends a plan whose first invoice was never paid at once', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Canceled)
            ->and($this->invoicesOf($this->acme)->sole()->status)->toBe(InvoiceStatus::Void);
    });

    it('drops a scheduled change of plan and an unpaid upgrade', function (string $stage) {
        ($this->enterStage)($stage);

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->cancel_at_period_end)->toBeTrue()
            ->and($subscription->pending_plan_id)->toBeNull()
            ->and($subscription->pending_interval)->toBeNull()
            ->and($this->openInvoiceOf($this->acme))->toBeNull();
    })->with(['with a cheaper plan scheduled', 'with an upgrade waiting to be paid']);

    it('is refused once the subscription has ended', function (string $stage) {
        ($this->enterStage)($stage);

        $before = $this->subscriptionOf($this->acme)->getAttributes();
        $open = $this->openInvoiceOf($this->acme)?->id;

        expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel())))
            ->toBe(['subscription']);

        expect($this->subscriptionOf($this->acme)->getAttributes())->toBe($before)
            // An expired workspace can still pay what it owes.
            ->and($this->openInvoiceOf($this->acme)?->id)->toBe($open);
    })->with(['expired', 'ended by cancellation']);

    it('is refused for a workspace without a subscription', function () {
        expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel())))
            ->toBe(['subscription']);
    });
});

describe('resuming', function () {
    it('is refused when the subscription is not set to end', function (string $stage) {
        ($this->enterStage)($stage);

        $before = $this->subscriptionOf($this->acme)->getAttributes();

        expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->resume())))
            ->toBe(['subscription']);

        expect($this->subscriptionOf($this->acme)->getAttributes())->toBe($before)
            ->and($this->auditTrail($this->acme, 'billing.subscription_resumed'))->toBeEmpty();
    })->with([
        'on trial, with its first invoice issued',
        'past due',
        'with a cheaper plan scheduled',
        'on a free plan',
        // Too late: it has to take a plan again.
        'expired',
        'ended by cancellation',
    ]);

    it('is refused for a workspace without a subscription', function () {
        expect(($this->refusal)(fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->resume())))
            ->toBe(['subscription']);
    });

    it('brings back the invoice of a trial that was canceled after it had been issued', function () {
        $this->subscribe($this->acme, $this->pro);
        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        $first = $this->openInvoiceOf($this->acme);

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());

        expect($first->refresh()->status)->toBe(InvoiceStatus::Void);

        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme))->toBeNull();

        ($this->billing)(fn (SubscriptionManager $manager) => $manager->resume());
        $this->runBillingCycle($this->acme);

        $second = $this->openInvoiceOf($this->acme);

        expect($second)->not->toBeNull()
            ->and($second->id)->not->toBe($first->id)
            ->and($second->period_start->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($this->subscriptionOf($this->acme)->canceled_at)->toBeNull();
    });
});

describe('one open invoice at a time', function () {
    it('holds through every request and every pass of the cycle, in any order', function () {
        $open = fn (): int => Invoice::forTenant($this->acme)->open()->count();
        $cycle = fn () => $this->runBillingCycle($this->acme);

        $steps = [
            'a trial starts' => [fn () => $this->subscribe($this->acme, $this->pro), 0],
            'the cycle runs early' => [$cycle, 0],
            'a week before the trial ends' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-03-17 09:00:00'));
                $cycle();
                $cycle();
            }, 1],
            'the plan is changed during the trial' => [fn () => ($this->change)($this->business), 0],
            'the cycle asks for the new plan' => [$cycle, 1],
            'the trial is canceled' => [fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel()), 0],
            'and resumed' => [fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->resume()), 0],
            'the cycle asks again' => [$cycle, 1],
            'the trial ends unpaid' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-03-24 10:00:00'));
                $cycle();
            }, 1],
            'another plan is chosen while past due' => [fn () => ($this->change)($this->starter), 1],
            'and another' => [fn () => ($this->change)($this->pro, BillingInterval::Yearly), 1],
            'the cycle runs' => [$cycle, 1],
            'the grace period runs out' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-04-02 09:00:00'));
                $cycle();
            }, 1],
            'a plan is taken again' => [fn () => ($this->change)($this->starter), 1],
            'it is paid' => [fn () => $this->payOpenInvoice($this->acme), 0],
            'an upgrade is asked for' => [fn () => ($this->change)($this->business), 1],
            'and a different one' => [fn () => ($this->change)($this->business, BillingInterval::Yearly), 1],
            'the cycle runs before the offer lapses' => [$cycle, 1],
            'the offer lapses' => [function () use ($cycle): void {
                $this->travel(4)->days();
                $cycle();
            }, 0],
            'the renewal comes up' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-04-26 09:00:00'));
                $cycle();
            }, 1],
            'an upgrade is asked for instead' => [fn () => ($this->change)($this->business), 1],
            'the upgrade lapses and the renewal is asked for again' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-04-30 09:00:00'));
                $cycle();
            }, 1],
            'a downgrade is scheduled' => [fn () => ($this->change)($this->free), 0],
            'the downgrade is undone' => [fn () => ($this->change)($this->starter), 0],
            'the cycle asks for the renewal a third time' => [$cycle, 1],
            'the period ends unpaid' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-05-02 10:00:00'));
                $cycle();
            }, 1],
            'the operator extends a trial' => [fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->extendTrial(Carbon::parse('2026-05-20 09:00:00'))), 0],
            'the cycle asks before the trial ends' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-05-14 09:00:00'));
                $cycle();
            }, 1],
            'the operator grants a plan' => [fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->grant($this->business, BillingInterval::Monthly)), 0],
            'the granted period nears its end' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-06-10 09:00:00'));
                $cycle();
            }, 1],
            'the workspace cancels' => [fn () => ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel()), 0],
            'the period ends' => [function () use ($cycle): void {
                $this->travelTo(Carbon::parse('2026-06-15 09:00:00'));
                $cycle();
            }, 0],
        ];

        foreach ($steps as $step => [$act, $expected]) {
            $act();

            expect($open())->toBe($expected, "After [{$step}]");
        }

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Canceled);
    });
});

describe('settling an invoice', function () {
    it('happens once, however often it is asked for', function () {
        $paid = 0;
        Event::listen(InvoicePaid::class, function () use (&$paid): void {
            $paid++;
        });

        $this->subscribeAndPay($this->acme, $this->starter);
        $this->travelTo(Carbon::parse('2026-04-05 09:00:00'));
        $this->runBillingCycle($this->acme);

        // A copy read before the payment: it goes on saying "open".
        $renewal = $this->openInvoiceOf($this->acme);

        $settled = $this->inTenant($this->acme, fn (): array => [
            app(SubscriptionManager::class)->settle($renewal, Carbon::parse('2026-04-05 12:00:00')),
            app(SubscriptionManager::class)->settle($renewal, Carbon::parse('2026-04-06 12:00:00')),
            app(SubscriptionManager::class)->settle($renewal->fresh()),
        ]);

        $subscription = $this->subscriptionOf($this->acme);

        expect($settled)->toBe([true, false, false])
            ->and($renewal->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($renewal->paid_at->toDateTimeString())->toBe('2026-04-05 12:00:00')
            // One period further, not three.
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-10 09:00:00')
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toHaveCount(2)
            ->and($this->auditTrail($this->acme, 'billing.subscription_activated'))->toHaveCount(2)
            ->and($paid)->toBe(2);
    });

    it('does nothing for an invoice that was withdrawn', function () {
        $updates = 0;
        Event::listen(SubscriptionUpdated::class, function () use (&$updates): void {
            $updates++;
        });

        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $invoice = $this->openInvoiceOf($this->acme);

        $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($invoice));

        $before = $this->subscriptionOf($this->acme)->getAttributes();
        $announced = $updates;

        expect($this->inTenant($this->acme, fn (): bool => app(SubscriptionManager::class)->settle($invoice)))->toBeFalse()
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($invoice->paid_at)->toBeNull()
            ->and($this->subscriptionOf($this->acme)->getAttributes())->toBe($before)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->auditTrail($this->acme, 'billing.invoice_paid'))->toBeEmpty()
            ->and($updates)->toBe($announced);
    });

    it('cannot void an invoice that has been paid', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        $invoice = $this->invoicesOf($this->acme)->sole();

        $this->inTenant($this->acme, function () use ($invoice): void {
            app(InvoiceIssuer::class)->void($invoice);
            app(InvoiceIssuer::class)->voidOpen();
        });

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->voided_at)->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.invoice_voided'))->toBeEmpty();
    });

    it('is not undone by withdrawing a copy of the invoice that was read before the payment', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        // Read while it was open - by the cycle, or by an operator looking at it - and paid a moment later.
        $stale = $this->openInvoiceOf($this->acme);

        $this->payOpenInvoice($this->acme);

        expect($stale->isOpen())->toBeTrue();

        $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($stale));

        $invoice = $this->invoicesOf($this->acme)->sole();

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->voided_at)->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.invoice_voided'))->toBeEmpty()
            // The copy in hand is put right as well.
            ->and($stale->status)->toBe(InvoiceStatus::Paid)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
    });

    it('withdraws an open invoice once, and says so on the copy it was given', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $invoice = $this->openInvoiceOf($this->acme);
        $copy = $this->openInvoiceOf($this->acme);

        $this->travel(2)->hours();

        $this->inTenant($this->acme, function () use ($invoice, $copy): void {
            app(InvoiceIssuer::class)->void($invoice);

            $this->travel(1)->hours();

            // Withdrawn already: neither the time nor the trail changes.
            app(InvoiceIssuer::class)->void($copy);
            app(InvoiceIssuer::class)->void($invoice);
        });

        expect($invoice->status)->toBe(InvoiceStatus::Void)
            ->and($copy->status)->toBe(InvoiceStatus::Void)
            ->and($this->invoicesOf($this->acme)->sole()->voided_at->toDateTimeString())->toBe('2026-03-10 11:00:00')
            ->and($this->auditTrail($this->acme, 'billing.invoice_voided'))->toHaveCount(1);
    });

    it('does not let one workspace withdraw the invoice of another', function () {
        $globex = $this->createTenant('globex');

        $this->subscribe($globex, $this->starter, withTrial: false);

        $theirs = $this->openInvoiceOf($globex);

        $this->inTenant($this->acme, fn () => app(InvoiceIssuer::class)->void($theirs));

        expect($this->openInvoiceOf($globex)?->id)->toBe($theirs->id)
            ->and($this->auditTrail($this->acme, 'billing.invoice_voided'))->toBeEmpty()
            ->and($this->auditTrail($globex, 'billing.invoice_voided'))->toBeEmpty();
    });

    it('records the payment of an invoice that buys no plan, and leaves the subscription alone', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        $before = $this->subscriptionOf($this->acme)->getAttributes();

        $oneOff = $this->inTenant($this->acme, fn (): Invoice => Invoice::query()->create([
            'number' => 'INV-2026-900001',
            'kind' => InvoiceKind::Subscription,
            'status' => InvoiceStatus::Open,
            'currency' => 'BDT',
            'subtotal_amount' => 50000,
            'credit_amount' => 0,
            'total_amount' => 50000,
            'lines' => [['type' => 'plan', 'plan' => 'Setup', 'interval' => 'monthly', 'amount' => 50000]],
            'issued_at' => Carbon::now(),
        ]));

        expect($this->inTenant($this->acme, fn (): bool => app(SubscriptionManager::class)->settle($oneOff)))->toBeTrue()
            ->and($oneOff->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($this->subscriptionOf($this->acme)->getAttributes())->toBe($before);
    });

    it('cannot reach an invoice of another workspace', function () {
        $globex = $this->createTenant('globex');

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $this->subscribe($globex, $this->starter, withTrial: false);

        $theirs = $this->openInvoiceOf($globex);

        expect(fn () => $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->settle($theirs)))
            ->toThrow(ModelNotFoundException::class);

        expect($theirs->refresh()->status)->toBe(InvoiceStatus::Open)
            ->and($this->subscriptionOf($globex)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    });
});

describe('the end of a month', function () {
    it('ends a period that starts on 31 January on the last day of February', function () {
        $this->travelTo(Carbon::parse('2027-01-31 09:00:00'));

        $subscription = $this->subscribeAndPay($this->acme, $this->starter);
        $invoice = $this->invoicesOf($this->acme)->sole();

        expect($subscription->current_period_start->toDateTimeString())->toBe('2027-01-31 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2027-02-28 09:00:00')
            ->and($invoice->period_end->toDateTimeString())->toBe('2027-02-28 09:00:00');

        // The renewal is asked for a week before that day, and starts on it.
        $this->travelTo(Carbon::parse('2027-02-21 09:00:00'));
        $this->runBillingCycle($this->acme);

        $renewal = $this->openInvoiceOf($this->acme);

        expect($renewal->period_start->toDateTimeString())->toBe('2027-02-28 09:00:00')
            ->and($renewal->period_end->toDateTimeString())->toBe('2027-03-28 09:00:00')
            ->and($renewal->due_at->toDateTimeString())->toBe('2027-02-28 09:00:00');
    });

    it('ends it on 29 February in a leap year', function () {
        $this->travelTo(Carbon::parse('2028-01-31 09:00:00'));

        expect($this->subscribeAndPay($this->acme, $this->starter)->current_period_end->toDateTimeString())->toBe('2028-02-29 09:00:00');
    });

    it('ends a year that starts on 29 February on 28 February', function () {
        $this->travelTo(Carbon::parse('2028-02-29 09:00:00'));

        $subscription = $this->subscribeAndPay($this->acme, $this->starter, BillingInterval::Yearly);

        expect($subscription->current_period_start->toDateTimeString())->toBe('2028-02-29 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2029-02-28 09:00:00')
            ->and($this->invoicesOf($this->acme)->sole()->period_end->toDateTimeString())->toBe('2029-02-28 09:00:00');
    });

    it('bills the first period after a trial that ends on the 31st to the end of the next month', function () {
        $this->travelTo(Carbon::parse('2027-01-17 09:00:00'));
        $this->subscribe($this->acme, $this->pro);

        $this->travelTo(Carbon::parse('2027-01-24 09:00:00'));
        $this->runBillingCycle($this->acme);

        $invoice = $this->openInvoiceOf($this->acme);

        expect($invoice->period_start->toDateTimeString())->toBe('2027-01-31 09:00:00')
            ->and($invoice->period_end->toDateTimeString())->toBe('2027-02-28 09:00:00');
    });

    it('credits an upgrade by the real length of the month', function () {
        // February 2027 has 28 days: 14 of them are left on the 15th.
        $this->travelTo(Carbon::parse('2027-02-01 00:00:00'));
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->travelTo(Carbon::parse('2027-02-15 00:00:00'));

        expect(($this->change)($this->business)->invoice->credit_amount)->toBe(75000);
    });
});

describe('domain events', function () {
    beforeEach(function () {
        $this->heard = [];

        Event::listen([SubscriptionUpdated::class, InvoicePaid::class], function (DomainEvent $event): void {
            $this->heard[] = $event;
        });
    });

    it('announces a change of the subscription only once it is committed', function () {
        expect(is_subclass_of(SubscriptionUpdated::class, ShouldDispatchAfterCommit::class))->toBeTrue()
            ->and(is_subclass_of(InvoicePaid::class, ShouldDispatchAfterCommit::class))->toBeTrue();

        $this->inTenant($this->acme, function (): void {
            DB::transaction(function (): void {
                app(SubscriptionManager::class)->start($this->starter, BillingInterval::Monthly, withTrial: false);

                expect($this->heard)->toBe([]);
            });
        });

        expect($this->heard)->toHaveCount(1);

        $event = $this->heard[0];
        $subscription = $this->subscriptionOf($this->acme);

        expect($event)->toBeInstanceOf(SubscriptionUpdated::class)
            ->and($event::name())->toBe('core.subscription.updated')
            ->and($event->tenantId())->toBe($this->acme->id)
            ->and($event->occurredAt()->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($event->payload())->toBe([
                'subscription_id' => $subscription->id,
                'plan_id' => $this->starter->id,
                'status' => 'past_due',
                'interval' => 'monthly',
            ]);
    });

    it('announces a paid invoice, then the subscription it switched on', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $this->heard = [];

        $invoice = $this->openInvoiceOf($this->acme);

        $this->inTenant($this->acme, function () use ($invoice): void {
            DB::transaction(function () use ($invoice): void {
                app(SubscriptionManager::class)->settle($invoice);

                expect($this->heard)->toBe([]);
            });
        });

        expect($this->heard)->toHaveCount(2);

        [$paid, $updated] = $this->heard;

        expect($paid)->toBeInstanceOf(InvoicePaid::class)
            ->and($paid::name())->toBe('core.invoice.paid')
            ->and($paid->tenantId())->toBe($this->acme->id)
            ->and($paid->payload())->toBe([
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'total_amount' => 150000,
                'currency' => 'BDT',
            ])
            ->and($updated)->toBeInstanceOf(SubscriptionUpdated::class)
            ->and($updated->payload()['status'])->toBe('active');
    });

    it('says nothing about a change that was rolled back', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $this->heard = [];

        try {
            $this->inTenant($this->acme, function (): void {
                DB::transaction(function (): void {
                    app(SubscriptionManager::class)->cancel();

                    throw new RuntimeException('Something else in the request failed.');
                });
            });
        } catch (RuntimeException) {
            // expected
        }

        expect($this->heard)->toBe([])
            ->and($this->subscriptionOf($this->acme)->cancel_at_period_end)->toBeFalse();
    });

    it('carries ids and scalars only, never a model', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        ($this->change)($this->business);
        $this->payOpenInvoice($this->acme);
        ($this->billing)(fn (SubscriptionManager $manager) => $manager->cancel());

        expect($this->heard)->not->toBeEmpty();

        foreach ($this->heard as $event) {
            $values = [...array_values(get_object_vars($event)), ...array_values($event->payload())];

            expect(array_filter($values, 'is_object'))->toBe([], $event::class.' holds an object')
                ->and(array_filter($values, 'is_array'))->toBe([], $event::class.' holds an array')
                ->and(serialize($event))->not->toContain('App\\Core\\Billing\\Models')
                ->and($event->tenantId())->toBe($this->acme->id);
        }

        expect(array_map(fn (DomainEvent $event): string => $event::name().':'.($event->payload()['status'] ?? 'paid'), $this->heard))->toBe([
            'core.subscription.updated:past_due',
            'core.invoice.paid:paid',
            'core.subscription.updated:active',
            'core.invoice.paid:paid',
            'core.subscription.updated:active',
            'core.subscription.updated:active',
        ]);
    });

    it('announces what the cycle does as well', function () {
        $this->subscribe($this->acme, $this->pro);
        $this->heard = [];

        $this->travelTo(Carbon::parse('2026-03-24 10:00:00'));
        $this->runBillingCycle($this->acme);
        $this->travelTo(Carbon::parse('2026-04-01 10:00:00'));
        $this->runBillingCycle($this->acme);
        // A pass that changes nothing announces nothing.
        $this->runBillingCycle($this->acme);

        expect(array_map(fn (DomainEvent $event): string => $event->payload()['status'], $this->heard))->toBe(['past_due', 'expired']);
    });
});

describe('invoice numbers', function () {
    beforeEach(function () {
        $this->sequence = fn (Invoice $invoice): int => (int) substr($invoice->number, -6);
    });

    it('run in one sequence for the whole platform and are never used twice', function () {
        $globex = $this->createTenant('globex');

        $this->subscribe($this->acme, $this->starter, withTrial: false);
        $this->subscribe($globex, $this->business, withTrial: false);
        // Voids the first invoice of acme and issues another: the number of a void invoice is not handed out again.
        ($this->change)($this->business);
        $this->inTenant($globex, fn () => app(SubscriptionManager::class)->changePlan($this->starter, BillingInterval::Yearly));

        $acme = $this->invoicesOf($this->acme);
        $theirs = $this->invoicesOf($globex);
        $first = ($this->sequence)($acme[0]);

        expect($acme)->toHaveCount(2)
            ->and($theirs)->toHaveCount(2)
            ->and($acme[0]->status)->toBe(InvoiceStatus::Void)
            ->and(array_map($this->sequence, [$acme[0], $theirs[0], $acme[1], $theirs[1]]))->toBe([$first, $first + 1, $first + 2, $first + 3]);

        foreach ([...$acme, ...$theirs] as $invoice) {
            expect($invoice->number)->toMatch('/^INV-2026-\d{6}$/');
        }

        expect(Invoice::withoutTenancy()->distinct()->count('number'))->toBe(4);
    });

    it('carry the configured prefix and the year of issue, without starting over in a new year', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $first = $this->openInvoiceOf($this->acme);

        config(['platform.billing.invoice_prefix' => 'OYO']);
        $this->travelTo(Carbon::parse('2027-01-05 09:00:00'));

        $second = ($this->change)($this->business)->invoice;

        expect($first->number)->toStartWith('INV-2026-')
            ->and($second->number)->toBe(sprintf('OYO-2027-%06d', ($this->sequence)($first) + 1));
    });

    it('leave no gap when the invoice a number was taken for is not saved', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $last = ($this->sequence)($this->openInvoiceOf($this->acme));

        try {
            DB::transaction(function () use ($last): void {
                expect(app(InvoiceNumbers::class)->next())->toBe(sprintf('INV-2026-%06d', $last + 1));

                throw new RuntimeException('The invoice could not be saved.');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(($this->sequence)(($this->change)($this->business)->invoice))->toBe($last + 1);
    });

    it('count on, past the width they are padded to', function () {
        DB::table('platform_sequences')->upsert([['name' => 'invoice', 'value' => 999999]], ['name'], ['value']);

        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect($this->openInvoiceOf($this->acme)->number)->toBe('INV-2026-1000000');
    });
});

describe('who an invoice is billed to', function () {
    beforeEach(function () {
        $this->profile = [
            'name' => 'Acme Trading Limited',
            'email' => 'accounts@acme.example',
            'phone' => '+8801700000000',
            'address' => 'House 12, Road 5, Dhanmondi',
            'city' => 'Dhaka',
            'postal_code' => '1205',
            'country' => 'BD',
            'tax_id' => 'BIN-000123456',
        ];

        $this->acme->update([...$this->profile, 'name' => 'Acme', 'legal_name' => 'Acme Trading Limited']);
    });

    it('is the company profile as it was when the invoice was issued', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect($this->openInvoiceOf($this->acme)->billed_to)->toEqual($this->profile);
    });

    it('does not change when the profile changes later', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $invoice = $this->openInvoiceOf($this->acme);

        $this->inTenant($this->acme, fn () => app(UpdateWorkspaceProfile::class)->handle($this->acme, [
            'name' => 'Globex',
            'legal_name' => 'Globex Holdings PLC',
            'email' => 'finance@globex.example',
            'phone' => null,
            'address' => '1 Other Street',
            'city' => 'Chattogram',
            'postal_code' => '4000',
            'country' => 'BD',
            'tax_id' => null,
        ]));

        expect($invoice->refresh()->billed_to)->toEqual($this->profile);

        // Paying it does not rewrite it either.
        $this->payOpenInvoice($this->acme);

        expect($invoice->refresh()->billed_to)->toEqual($this->profile);

        // The next invoice is addressed to the company as it is now.
        $this->travelTo(Carbon::parse('2026-04-05 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme)->billed_to)->toEqual([
            'name' => 'Globex Holdings PLC',
            'email' => 'finance@globex.example',
            'phone' => null,
            'address' => '1 Other Street',
            'city' => 'Chattogram',
            'postal_code' => '4000',
            'country' => 'BD',
            'tax_id' => null,
        ]);
    });

    it('falls back to the workspace name when there is no legal name', function () {
        $this->acme->update(['legal_name' => null]);

        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect($this->openInvoiceOf($this->acme)->billed_to['name'])->toBe('Acme');
    });

    it('is the workspace the invoice is issued to, not the one next door', function () {
        $globex = $this->createTenant('globex', ['name' => 'Globex']);

        $this->subscribe($globex, $this->starter, withTrial: false);
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect($this->openInvoiceOf($globex)->billed_to['name'])->toBe('Globex')
            ->and($this->openInvoiceOf($globex)->billed_to['email'])->toBeNull()
            ->and($this->openInvoiceOf($this->acme)->billed_to['name'])->toBe('Acme Trading Limited');
    });
});

describe('tenant isolation', function () {
    it('isolates subscriptions between tenants', function () {
        $this->assertTenantIsolated(
            Subscription::class,
            fn (Tenant $tenant): Subscription => app(SubscriptionManager::class)->start($this->starter, BillingInterval::Monthly, withTrial: false),
        );
    });

    it('isolates invoices between tenants', function () {
        $this->assertTenantIsolated(Invoice::class, function (Tenant $tenant): Invoice {
            app(SubscriptionManager::class)->start($this->starter, BillingInterval::Monthly, withTrial: false);

            return Invoice::query()->sole();
        });
    });

    it('isolates payments between tenants', function () {
        $operator = $this->createPlatformUser();

        $this->assertTenantIsolated(Payment::class, function (Tenant $tenant) use ($operator): Payment {
            app(SubscriptionManager::class)->start($this->starter, BillingInterval::Monthly, withTrial: false);

            return app(PaymentProcessor::class)->recordOffline(Invoice::query()->sole(), $operator, 'bank_transfer');
        });
    });

    it('lets the cycle and the manager of one workspace see only its own subscription', function () {
        $globex = $this->createTenant('globex');

        $this->subscribe($this->acme, $this->pro);
        $this->subscribe($globex, $this->starter, withTrial: false);

        // Enough time for acme's trial to end and expire - and for globex to expire, if its own cycle ran.
        $this->travel(40)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Expired)
            ->and($this->subscriptionOf($globex)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->inTenant($globex, fn () => app(SubscriptionManager::class)->current()->id))->toBe($this->subscriptionOf($globex)->id)
            ->and($this->invoicesOf($globex))->toHaveCount(1);
    });
});
