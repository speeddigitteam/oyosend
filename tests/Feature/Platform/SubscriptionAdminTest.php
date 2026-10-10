<?php

declare(strict_types=1);

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Subscription;
use App\Core\Tenancy\TenantContext;
use App\Platform\Enums\PlatformRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * What only an operator may do to a workspace's subscription: put it on any
 * plan without an invoice, and move the end of its trial.
 *
 * Workspaces here are on Asia/Dhaka time (UTC+6): a day an operator picks is
 * a day on the workspace's own clock.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);

    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000]);
    $this->custom = $this->createPlan(['key' => 'custom', 'name' => 'Custom deal', 'monthly_amount' => 900000, 'yearly_amount' => 9000000, 'is_public' => false]);
    $this->free = $this->createFreePlan(['key' => 'free', 'name' => 'Free']);

    $this->grant = fn (array $input, $tenant = null, $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from('http://admin.localhost/tenants/'.($tenant ?? $this->acme)->id)
        ->post('http://admin.localhost/tenants/'.($tenant ?? $this->acme)->id.'/subscription', $input);

    $this->extendTrial = fn (array $input, $tenant = null, $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from('http://admin.localhost/tenants/'.($tenant ?? $this->acme)->id)
        ->post('http://admin.localhost/tenants/'.($tenant ?? $this->acme)->id.'/subscription/trial', $input);
});

describe('granting a plan', function () {
    it('puts a workspace without a subscription on a plan at once, without an invoice', function () {
        ($this->grant)(['plan_id' => $this->starter->id, 'interval' => 'monthly'])
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->is($this->starter))->toBeTrue()
            ->and($subscription->interval)->toBe(BillingInterval::Monthly)
            ->and($subscription->amount)->toBe(150000)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-03-10 09:00:00')
            // One interval from now, unless an end is given.
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-04-10 09:00:00')
            ->and($subscription->trial_ends_at)->toBeNull()
            ->and($subscription->grantsAccess())->toBeTrue()
            // Nothing is asked for: no invoice, no payment.
            ->and($this->invoicesOf($this->acme))->toBeEmpty()
            ->and($this->paymentsOf($this->acme))->toBeEmpty();

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('gives a plan that is not public', function () {
        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'yearly'])->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->plan->key)->toBe('custom')
            ->and($subscription->interval)->toBe(BillingInterval::Yearly)
            ->and($subscription->amount)->toBe(9000000)
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2027-03-10 09:00:00');
    });

    it('takes effect at once over whatever the workspace was on, and withdraws what it owed', function (string $case) {
        match ($case) {
            // Invoiced and unpaid.
            'unpaid' => $this->subscribe($this->acme, $this->starter, withTrial: false),
            'on a trial' => $this->subscribe($this->acme, $this->createPlan(['trial_days' => 14])),
            'in a paid period' => $this->subscribeAndPay($this->acme, $this->starter),
            'locked' => (function (): void {
                $this->subscribe($this->acme, $this->starter, withTrial: false);
                $this->travel(30)->days();
                $this->runBillingCycle($this->acme);
            })(),
        };

        $before = $this->subscriptionOf($this->acme);
        $owed = $this->openInvoiceOf($this->acme);

        expect($before->isLocked())->toBe($case === 'locked');

        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'monthly'])->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->id)->toBe($before->id)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->plan->key)->toBe('custom')
            ->and($subscription->amount)->toBe(900000)
            ->and($subscription->trial_ends_at)->toBeNull()
            ->and($subscription->grace_ends_at)->toBeNull()
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->isLocked())->toBeFalse()
            ->and($subscription->current_period_start->equalTo(now()))->toBeTrue()
            ->and($subscription->current_period_end->equalTo(now()->addMonthNoOverflow()))->toBeTrue()
            // The plan was given: nothing is left to pay, and nothing new is asked for.
            ->and($this->openInvoiceOf($this->acme))->toBeNull()
            ->and($owed?->refresh()->status)->toBe($owed === null ? null : InvoiceStatus::Void);
    })->with(['unpaid', 'on a trial', 'in a paid period', 'locked']);

    it('ends the granted period at the end of the chosen day, on the workspace\'s own clock', function () {
        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'monthly', 'period_end' => '2026-12-31'])
            ->assertSessionHasNoErrors();

        // 23:59:59 in Dhaka is 17:59:59 UTC.
        expect($this->subscriptionOf($this->acme)->current_period_end->toDateTimeString())->toBe('2026-12-31 17:59:59');
    });

    it('gives a free plan without a period, whatever end was asked for', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        ($this->grant)(['plan_id' => $this->free->id, 'interval' => 'monthly', 'period_end' => '2026-12-31'])->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->isFree())->toBeTrue()
            ->and($subscription->current_period_end)->toBeNull();
    });

    it('is recorded in the workspace\'s own trail, under the operator\'s name', function () {
        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'yearly', 'period_end' => '2026-12-31']);

        $entry = $this->auditTrail($this->acme, 'billing.plan_granted')->sole();

        expect($entry->tenant_id)->toBe($this->acme->id)
            ->and($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->actor_label)->toBe($this->admin->email)
            ->and($entry->subject_id)->toBe($this->subscriptionOf($this->acme)->id)
            ->and($entry->subject_label)->toBe('Custom deal')
            ->and($entry->properties)->toEqual([
                'plan' => 'custom',
                'interval' => 'yearly',
                'period_end' => '2026-12-31T17:59:59+00:00',
            ]);
    });

    it('validates what it is given', function (array $input, string $field) {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'monthly', ...$input])
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasErrors($field);

        // Nothing moved: still unpaid on the old plan.
        expect($this->subscriptionOf($this->acme)->plan->key)->toBe('starter')
            ->and($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->openInvoiceOf($this->acme))->not->toBeNull()
            ->and($this->auditTrail($this->acme, 'billing.plan_granted'))->toHaveCount(0);
    })->with([
        'no plan' => [['plan_id' => ''], 'plan_id'],
        'a malformed plan id' => [['plan_id' => 'custom'], 'plan_id'],
        'no interval' => [['interval' => ''], 'interval'],
        'an unknown interval' => [['interval' => 'weekly'], 'interval'],
        'an end that is not a date' => [['period_end' => 'next month'], 'period_end'],
        'an end today' => [['period_end' => '2026-03-10'], 'period_end'],
        'an end in the past' => [['period_end' => '2026-01-01'], 'period_end'],
    ]);

    it('refuses an interval the plan is not sold for', function () {
        // Sold by the month only.
        ($this->grant)(['plan_id' => $this->free->id, 'interval' => 'yearly'])
            ->assertSessionHasErrors(['interval' => 'This plan is not available for that billing interval.']);

        expect(Subscription::forTenant($this->acme)->exists())->toBeFalse();
    });

    it('answers 404 for a plan or a workspace that does not exist', function () {
        ($this->grant)(['plan_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'interval' => 'monthly'])->assertNotFound();

        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/tenants/01ARZ3NDEKTSV4RRFFQ69G5FAV/subscription', ['plan_id' => $this->starter->id, 'interval' => 'monthly'])
            ->assertNotFound();

        expect(Subscription::withoutTenancy()->count())->toBe(0);
    });

    it('touches the subscription of that workspace only', function () {
        $globex = $this->createTenant('globex');
        $this->subscribe($globex, $this->starter, withTrial: false);

        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'monthly']);

        expect($this->subscriptionOf($globex)->plan->key)->toBe('starter')
            ->and($this->subscriptionOf($globex)->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->openInvoiceOf($globex))->not->toBeNull()
            ->and($this->auditTrail($globex, 'billing.plan_granted'))->toHaveCount(0);
    });
});

describe('extending a trial', function () {
    beforeEach(function () {
        $this->pro = $this->createPlan(['key' => 'pro', 'name' => 'Pro', 'trial_days' => 14, 'monthly_amount' => 200000]);
    });

    it('moves the end of a trial to the end of the chosen day, on the workspace\'s own clock', function () {
        $this->subscribe($this->acme, $this->pro);

        expect($this->subscriptionOf($this->acme)->trial_ends_at->toDateTimeString())->toBe('2026-03-24 09:00:00');

        ($this->extendTrial)(['trial_ends_at' => '2026-04-30'])
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-04-30 17:59:59')
            ->and($subscription->plan->key)->toBe('pro');

        $entry = $this->auditTrail($this->acme, 'billing.trial_extended')->sole();

        expect($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->properties)->toEqual(['trial_ends_at' => '2026-04-30T17:59:59+00:00']);

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('can shorten a trial as well', function () {
        $this->subscribe($this->acme, $this->pro);

        ($this->extendTrial)(['trial_ends_at' => '2026-03-12'])->assertSessionHasNoErrors();

        expect($this->subscriptionOf($this->acme)->trial_ends_at->toDateTimeString())->toBe('2026-03-12 17:59:59');
    });

    it('puts a workspace whose trial ran out unpaid back on trial, and withdraws the invoice', function (string $case) {
        $this->subscribe($this->acme, $this->pro);

        // Past due after the trial; locked once the grace period is over as well.
        $this->travelTo(Carbon::parse($case === 'past due' ? '2026-03-25 09:00:00' : '2026-04-20 09:00:00'));
        $this->runBillingCycle($this->acme);

        $owed = $this->openInvoiceOf($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe($case === 'past due' ? SubscriptionStatus::PastDue : SubscriptionStatus::Expired)
            ->and($owed)->not->toBeNull();

        ($this->extendTrial)(['trial_ends_at' => '2026-05-15'])->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->acme);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-05-15 17:59:59')
            ->and($subscription->grace_ends_at)->toBeNull()
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->isLocked())->toBeFalse()
            // What was asked for the old trial end is no longer owed.
            ->and($owed->refresh()->status)->toBe(InvoiceStatus::Void)
            ->and($this->openInvoiceOf($this->acme))->toBeNull();
    })->with(['past due', 'locked']);

    it('validates the date', function (array $input) {
        $this->subscribe($this->acme, $this->pro);

        ($this->extendTrial)($input)
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasErrors('trial_ends_at');

        expect($this->subscriptionOf($this->acme)->trial_ends_at->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($this->auditTrail($this->acme, 'billing.trial_extended'))->toHaveCount(0);
    })->with([
        'none' => [[]],
        'empty' => [['trial_ends_at' => '']],
        'not a date' => [['trial_ends_at' => 'soon']],
        'today' => [['trial_ends_at' => '2026-03-10']],
        'in the past' => [['trial_ends_at' => '2026-03-01']],
        'a list' => [['trial_ends_at' => ['2026-04-30']]],
    ]);

    it('is not for a workspace inside a period it has paid for', function () {
        $this->subscribeAndPay($this->acme, $this->starter);
        $before = $this->subscriptionOf($this->acme)->getAttributes();

        ($this->extendTrial)(['trial_ends_at' => '2026-04-30'])
            ->assertSessionHasErrors(['trial_ends_at' => 'Only a plan that has not been paid for yet can be on trial.']);

        expect($this->subscriptionOf($this->acme)->getAttributes())->toEqual($before)
            ->and($this->auditTrail($this->acme, 'billing.trial_extended'))->toHaveCount(0);
    });

    it('is not for a free plan', function () {
        $this->subscribe($this->acme, $this->free);

        ($this->extendTrial)(['trial_ends_at' => '2026-04-30'])->assertSessionHasErrors('trial_ends_at');

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($this->subscriptionOf($this->acme)->trial_ends_at)->toBeNull();
    });

    it('is not for a workspace without a subscription', function () {
        ($this->extendTrial)(['trial_ends_at' => '2026-04-30'])
            ->assertSessionHasErrors(['subscription' => 'This workspace has no subscription.']);

        expect(Subscription::forTenant($this->acme)->exists())->toBeFalse();
    });
});

describe('who may do it', function () {
    it('is for an operator who may manage billing, which a support admin may not', function () {
        $this->subscribe($this->acme, $this->createPlan(['trial_days' => 14]));
        $before = $this->subscriptionOf($this->acme)->getAttributes();

        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'monthly'], as: $this->support)->assertForbidden();
        ($this->extendTrial)(['trial_ends_at' => '2026-04-30'], as: $this->support)->assertForbidden();

        expect($this->subscriptionOf($this->acme)->getAttributes())->toEqual($before)
            ->and($this->auditTrail($this->acme, 'billing.plan_granted'))->toHaveCount(0)
            ->and($this->auditTrail($this->acme, 'billing.trial_extended'))->toHaveCount(0);

        // The same two requests from a Super Admin go through.
        $this->startNewBrowserSession();

        ($this->extendTrial)(['trial_ends_at' => '2026-04-30'])->assertRedirect()->assertSessionHasNoErrors();
        ($this->grant)(['plan_id' => $this->custom->id, 'interval' => 'monthly'])->assertRedirect()->assertSessionHasNoErrors();

        expect($this->subscriptionOf($this->acme)->plan->key)->toBe('custom');
    });

    it('is not for guests, workspace owners or accounts without two-factor', function (string $path) {
        $this->subscribe($this->acme, $this->createPlan(['trial_days' => 14]));
        $before = $this->subscriptionOf($this->acme)->getAttributes();

        $url = "http://admin.localhost/tenants/{$this->acme->id}/{$path}";
        $input = ['plan_id' => $this->custom->id, 'interval' => 'monthly', 'trial_ends_at' => '2026-04-30'];

        $this->post($url, $input)->assertRedirect('http://admin.localhost/login');

        // The owner of the workspace cannot give it a plan through the panel.
        $this->actingAsOwner($this->acme)->post($url, $input)->assertRedirect('http://admin.localhost/login');

        $this->startNewBrowserSession()
            ->actingAsPlatformUser($this->createPlatformUser(twoFactor: false))
            ->post($url, $input)
            ->assertRedirect('http://admin.localhost/two-factor/setup');

        expect($this->subscriptionOf($this->acme)->getAttributes())->toEqual($before);
    })->with(['subscription', 'subscription/trial']);
});
