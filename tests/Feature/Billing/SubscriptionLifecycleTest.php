<?php

declare(strict_types=1);

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Mail\InvoiceIssuedMail;
use App\Core\Billing\Mail\PaymentReceivedMail;
use App\Core\Billing\SubscriptionManager;
use App\Core\Billing\Support\PlanChange;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * The subscription of a workspace from its first day to its last, driven the
 * way production drives it: SubscriptionManager for what people ask for,
 * BillingCycle for what time does, a recorded payment for money.
 *
 * Defaults (config/platform.php): the next invoice is issued 7 days before a
 * period or trial ends, and a workspace has 7 days of grace after it.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->acme = $this->createTenant('acme');
    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000]);
    $this->business = $this->createPlan(['key' => 'business', 'name' => 'Business', 'monthly_amount' => 450000, 'yearly_amount' => 4500000]);
    $this->free = $this->createFreePlan(['key' => 'free', 'name' => 'Free']);

    $this->change = fn ($plan, BillingInterval $interval = BillingInterval::Monthly): PlanChange => $this->inTenant(
        $this->acme,
        fn (): PlanChange => app(SubscriptionManager::class)->changePlan($plan, $interval),
    );
});

describe('a free plan', function () {
    it('is active at once, with no period, no invoice and nothing for time to do', function () {
        $subscription = $this->subscribe($this->acme, $this->free);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->amount)->toBe(0)
            ->and($subscription->current_period_end)->toBeNull()
            ->and($this->invoicesOf($this->acme))->toBeEmpty();

        $this->travel(400)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($this->invoicesOf($this->acme))->toBeEmpty();
    });

    it('has nothing to cancel', function () {
        $this->subscribe($this->acme, $this->free);

        expect(fn () => $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel()))
            ->toThrow(ValidationException::class);
    });
});

describe('a trial', function () {
    beforeEach(function () {
        $this->trialPlan = $this->createPlan(['key' => 'pro', 'name' => 'Pro', 'trial_days' => 14, 'monthly_amount' => 200000]);
        $this->subscribe($this->acme, $this->trialPlan);
    });

    it('starts without an invoice and issues one a week before it ends', function () {
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($this->invoicesOf($this->acme))->toBeEmpty();

        // Six days in: more than a week of trial left.
        $this->travel(6)->days();
        $this->runBillingCycle($this->acme);

        expect($this->invoicesOf($this->acme))->toBeEmpty();

        // Seven days before the end.
        $this->travel(1)->days();
        $this->runBillingCycle($this->acme);
        $this->runBillingCycle($this->acme);

        $invoice = $this->invoicesOf($this->acme)->sole();

        expect($invoice->kind)->toBe(InvoiceKind::Subscription)
            ->and($invoice->status)->toBe(InvoiceStatus::Open)
            ->and($invoice->total_amount)->toBe(200000)
            ->and($invoice->currency)->toBe('BDT')
            ->and($invoice->number)->toMatch('/^INV-2026-\d{6}$/')
            ->and($invoice->due_at->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($invoice->period_start->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($invoice->period_end->toDateTimeString())->toBe('2026-04-24 09:00:00')
            // A JSON column: MySQL returns the keys in its own order.
            ->and($invoice->lines)->toEqual([['type' => 'plan', 'plan' => 'Pro', 'interval' => 'monthly', 'amount' => 200000]])
            ->and($invoice->billed_to['name'])->toBe($this->acme->name)
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Trialing);

        Mail::assertQueued(InvoiceIssuedMail::class, 1);
    });

    it('becomes a paid period that starts when the trial ends, when paid early', function () {
        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        $payment = $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-24 09:00:00')
            ->and($this->invoicesOf($this->acme)->sole()->status)->toBe(InvoiceStatus::Paid)
            ->and($payment->gateway)->toBe('offline')
            ->and($payment->amount)->toBe(200000);

        Mail::assertQueued(PaymentReceivedMail::class, 1);
    });

    it('goes past due when it ends unpaid, then expires when the grace period ends', function () {
        $this->travelTo(Carbon::parse('2026-03-24 10:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-03-31 09:00:00')
            ->and($subscription->grantsAccess())->toBeTrue()
            ->and($this->openInvoiceOf($this->acme))->not->toBeNull();

        $this->travelTo(Carbon::parse('2026-03-31 10:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Expired)
            ->and($subscription->isLocked())->toBeTrue()
            ->and($subscription->ended_at)->not->toBeNull()
            // What is owed stays payable.
            ->and($this->openInvoiceOf($this->acme))->not->toBeNull();
    });

    it('expires in one pass when the cycle did not run for a long time', function () {
        $this->travel(60)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Expired)
            ->and($this->invoicesOf($this->acme))->toHaveCount(1);
    });

    it('comes back with a period that starts at the payment, once it had expired', function () {
        $this->travel(30)->days();
        $this->runBillingCycle($this->acme);
        $this->travel(5)->days();

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-14 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-14 09:00:00');
    });

    it('can change plan freely, and pays for whichever plan it ends on', function () {
        expect(($this->change)($this->business)->outcome)->toBe(PlanChange::APPLIED);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->amount)->toBe(450000);

        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme)->total_amount)->toBe(450000);
    });

    it('ends without an invoice when it was canceled', function () {
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());

        $this->travel(15)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Canceled)
            ->and($this->invoicesOf($this->acme))->toBeEmpty();
    });
});

describe('a paid plan taken without a trial', function () {
    it('is invoiced at once and has the grace period to pay', function () {
        $subscription = $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->grace_ends_at->toDateTimeString())->toBe('2026-03-17 09:00:00')
            ->and($this->openInvoiceOf($this->acme)->total_amount)->toBe(150000);

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->grace_ends_at)->toBeNull();
    });

    it('is locked when the first invoice is never paid', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Expired);
    });

    it('bills a year at the yearly price', function () {
        $subscription = $this->subscribeAndPay($this->acme, $this->starter, BillingInterval::Yearly);

        expect($subscription->amount)->toBe(1500000)
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2027-03-10 09:00:00');
    });
});

describe('renewal', function () {
    beforeEach(fn () => $this->subscribeAndPay($this->acme, $this->starter));

    it('issues the next invoice a week before the period ends and extends the period when it is paid', function () {
        $this->travelTo(Carbon::parse('2026-04-02 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme))->toBeNull();

        $this->travelTo(Carbon::parse('2026-04-03 09:00:00'));
        $this->runBillingCycle($this->acme);

        $invoice = $this->openInvoiceOf($this->acme);

        expect($invoice->kind)->toBe(InvoiceKind::Renewal)
            ->and($invoice->period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($invoice->due_at->toDateTimeString())->toBe('2026-04-10 09:00:00');

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-10 09:00:00');
    });

    it('keeps the period where it was when the renewal is paid during the grace period', function () {
        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        // Paid two days late: the period still runs from when the last one ended.
        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-05-10 09:00:00');
    });

    it('renews at the price the plan was taken at, whatever the plan costs today', function () {
        $this->starter->update(['monthly_amount' => 999900]);

        $this->travelTo(Carbon::parse('2026-04-05 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme)->total_amount)->toBe(150000);
    });
});

describe('moving to a more expensive plan inside a paid period', function () {
    beforeEach(fn () => $this->subscribeAndPay($this->acme, $this->starter));

    it('credits what is left of the old period and starts a new one when paid', function () {
        // 31 days in the period (10 March to 10 April); 20 of them are left.
        $this->travelTo(Carbon::parse('2026-03-21 09:00:00'));

        $change = ($this->change)($this->business);
        $invoice = $change->invoice;

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($invoice->kind)->toBe(InvoiceKind::Change)
            ->and($invoice->subtotal_amount)->toBe(450000)
            ->and($invoice->credit_amount)->toBe(intdiv(150000 * 20, 31))
            ->and($invoice->total_amount)->toBe(450000 - intdiv(150000 * 20, 31))
            ->and($invoice->lines[1])->toEqual(['type' => 'credit', 'amount' => -intdiv(150000 * 20, 31)])
            // Nothing changes until the money is in.
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');

        $this->travel(1)->days();
        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->plan->key)->toBe('business')
            ->and($subscription->amount)->toBe(450000)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-22 09:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-22 09:00:00');
    });

    it('withdraws the offer after three days, because its credit was worked out for the day it was made', function () {
        ($this->change)($this->business);

        $this->travel(4)->days();
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($this->invoicesOf($this->acme)->last()->status)->toBe(InvoiceStatus::Void)
            ->and($this->subscriptionOf($this->acme)->plan->key)->toBe('starter');
    });

    it('refuses the plan the workspace is already on', function () {
        expect(fn () => ($this->change)($this->starter))->toThrow(ValidationException::class);
    });
});

describe('moving to a cheaper plan inside a paid period', function () {
    beforeEach(fn () => $this->subscribeAndPay($this->acme, $this->business));

    it('waits for the period to end and is what the next period is billed for', function () {
        $change = ($this->change)($this->starter);

        expect($change->outcome)->toBe(PlanChange::SCHEDULED)
            ->and($change->effectiveAt->toDateTimeString())->toBe('2026-04-10 09:00:00');

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->plan->key)->toBe('business')
            ->and($subscription->pendingPlan->key)->toBe('starter');

        $this->travelTo(Carbon::parse('2026-04-04 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->openInvoiceOf($this->acme)->total_amount)->toBe(150000);

        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->plan->key)->toBe('starter')
            ->and($subscription->amount)->toBe(150000)
            ->and($subscription->pending_plan_id)->toBeNull()
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-04-10 09:00:00');
    });

    it('moves to the free plan at the end of the period, without an invoice', function () {
        expect(($this->change)($this->free)->outcome)->toBe(PlanChange::SCHEDULED);

        $this->travelTo(Carbon::parse('2026-04-10 10:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->plan->key)->toBe('free')
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->current_period_end)->toBeNull()
            ->and($this->openInvoiceOf($this->acme))->toBeNull();
    });

    it('is undone by choosing the current plan again', function () {
        ($this->change)($this->starter);

        expect(($this->change)($this->business)->outcome)->toBe(PlanChange::APPLIED)
            ->and($this->subscriptionOf($this->acme)->pending_plan_id)->toBeNull();
    });
});

describe('cancelling', function () {
    beforeEach(fn () => $this->subscribeAndPay($this->acme, $this->starter));

    it('keeps the workspace running until the paid period ends, then locks it', function () {
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->cancel_at_period_end)->toBeTrue();

        $this->travelTo(Carbon::parse('2026-04-10 10:00:00'));
        $this->runBillingCycle($this->acme);

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
            ->and($subscription->isLocked())->toBeTrue()
            // No renewal was asked for.
            ->and($this->invoicesOf($this->acme))->toHaveCount(1);
    });

    it('can be taken back before the period ends', function () {
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->resume());

        $this->travelTo(Carbon::parse('2026-04-10 10:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    });

    it('lets a workspace that has ended take a plan again, which starts when it is paid', function () {
        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());
        $this->travelTo(Carbon::parse('2026-05-01 09:00:00'));
        $this->runBillingCycle($this->acme);

        $change = ($this->change)($this->business);

        expect($change->outcome)->toBe(PlanChange::AWAITING_PAYMENT)
            ->and($this->subscriptionOf($this->acme)->isLocked())->toBeTrue();

        $this->travel(2)->days();
        $this->payOpenInvoice($this->acme);
        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('business')
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-05-03 09:00:00');
    });
});

describe('what a plan entitles a workspace to', function () {
    beforeEach(function () {
        $this->withCrm = $this->createPlan(['key' => 'crm-plan', 'modules' => ['crm'], 'trial_days' => 14]);
        $this->entitled = fn (): array => $this->inTenant($this->acme, fn (): array => app(EntitlementChecker::class)->entitledModules($this->acme));
    });

    it('gives only the core modules to a workspace without a subscription', function () {
        expect(($this->entitled)())->toBe(['core']);
    });

    it('includes the plan\'s modules during the trial, a paid period and the grace period - and not once it has ended', function () {
        $this->subscribe($this->acme, $this->withCrm);

        expect(($this->entitled)())->toBe(['core', 'crm']);

        // Trial over, unpaid: past due, still inside the grace period.
        $this->travel(15)->days();
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and(($this->entitled)())->toBe(['core', 'crm']);

        // Grace over: locked. The core modules stay, so the owner can still reach billing.
        $this->travel(8)->days();
        $this->runBillingCycle($this->acme);

        expect(($this->entitled)())->toBe(['core']);

        $this->payOpenInvoice($this->acme);

        expect(($this->entitled)())->toBe(['core', 'crm']);
    });

    it('does not include a module the plan does not name', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect(($this->entitled)())->toBe(['core']);
    });
});
