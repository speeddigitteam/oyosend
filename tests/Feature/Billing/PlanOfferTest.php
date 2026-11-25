<?php

declare(strict_types=1);

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Website\SitePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * A limited-time offer on a plan (docs/BILLING.md): a lower price per interval until the end of a day. A workspace
 * that takes the plan meanwhile is billed the offer price and keeps it when it renews, as it keeps any price it took
 * a plan at. Money is integer minor units: BDT 1,500.00 is 150000.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->plan = $this->createPlan([
        'key' => 'business',
        'monthly_amount' => 450000,
        'yearly_amount' => 4500000,
        'offer_monthly_amount' => 300000,
        'offer_ends_at' => Carbon::parse('2026-03-31 17:59:59'),
    ]);
});

describe('the price', function () {
    it('is the offer\'s while it runs, for the intervals it covers, and the plan\'s own afterwards', function () {
        expect($this->plan->amountFor(BillingInterval::Monthly))->toBe(300000)
            ->and($this->plan->amountFor(BillingInterval::Yearly))->toBe(4500000)
            ->and($this->plan->listAmountFor(BillingInterval::Monthly))->toBe(450000);

        $this->travelTo(Carbon::parse('2026-03-31 18:00:00'));

        expect($this->plan->amountFor(BillingInterval::Monthly))->toBe(450000)
            ->and($this->plan->hasLiveOffer())->toBeFalse();
    });

    it('ignores an offer that is not below the plan\'s own price', function () {
        $this->plan->forceFill(['offer_monthly_amount' => 450000])->save();

        expect($this->plan->amountFor(BillingInterval::Monthly))->toBe(450000);
    });

    it('is what the pages are sent, with the plan\'s own price beside it', function () {
        expect(BillingPresenter::plan($this->plan))->toMatchArray([
            'monthlyAmount' => 300000,
            'yearlyAmount' => 4500000,
            'listMonthlyAmount' => 450000,
            'listYearlyAmount' => 4500000,
            'offerEndsAt' => '2026-03-31T17:59:59+00:00',
        ]);

        $this->travelTo(Carbon::parse('2026-04-01 00:00:00'));

        expect(BillingPresenter::plan($this->plan))->toMatchArray(['monthlyAmount' => 450000, 'offerEndsAt' => null]);
    });
});

describe('a workspace that takes the plan during the offer', function () {
    it('is billed the offer price and keeps it when it renews after the offer has ended', function () {
        $acme = $this->createTenant('acme');
        $this->subscribe($acme, $this->plan, withTrial: false);

        expect($this->openInvoiceOf($acme)->total_amount)->toBe(300000);

        $this->payOpenInvoice($acme);

        expect($this->subscriptionOf($acme)->amount)->toBe(300000);

        // A week before the period ends, long after the offer did.
        $this->travelTo(Carbon::parse('2026-04-03 09:00:00'));
        $this->runBillingCycle($acme);

        $renewal = $this->openInvoiceOf($acme);

        expect($renewal->kind)->toBe(InvoiceKind::Renewal)
            ->and($renewal->total_amount)->toBe(300000);
    });

    it('pays the plan\'s own price once the offer has ended', function () {
        $this->travelTo(Carbon::parse('2026-04-01 09:00:00'));

        $globex = $this->createTenant('globex');
        $this->subscribe($globex, $this->plan, withTrial: false);

        expect($this->openInvoiceOf($globex)->total_amount)->toBe(450000);
    });
});

describe('setting one up in the panel', function () {
    beforeEach(function () {
        $this->admin = $this->createPlatformUser();

        $this->save = fn (array $input): TestResponse => $this->actingAsPlatformUser($this->admin)
            ->from("http://admin.localhost/plans/{$this->plan->id}/edit")
            ->put("http://admin.localhost/plans/{$this->plan->id}", [
                'key' => 'business', 'name' => 'Business', 'currency' => 'BDT',
                'monthly_amount' => 450000, 'yearly_amount' => 4500000, 'trial_days' => 0,
                'modules' => [], 'limits' => [], 'is_public' => true, 'is_active' => true, 'sort_order' => 0,
                ...$input,
            ]);
    });

    it('ends the offer at the close of its last day in Dhaka, and shows the form what it stored', function () {
        ($this->save)(['offer_monthly_amount' => 350000, 'offer_yearly_amount' => 3600000, 'offer_ends_on' => '2026-04-15'])
            ->assertSessionHasNoErrors();

        $plan = $this->plan->refresh();

        expect($plan->offer_monthly_amount)->toBe(350000)
            ->and($plan->offer_yearly_amount)->toBe(3600000)
            // 23:59:59 in Asia/Dhaka (UTC+6).
            ->and($plan->offer_ends_at->toDateTimeString())->toBe('2026-04-15 17:59:59');

        $this->get("http://admin.localhost/plans/{$plan->id}/edit")
            ->assertInertia(fn ($page) => $page
                ->where('plan.monthlyAmount', 450000)
                ->where('plan.offerMonthlyAmount', 350000)
                ->where('plan.offerEndsOn', '2026-04-15'));
    });

    it('clears the offer when both prices are left empty', function () {
        ($this->save)(['offer_monthly_amount' => null, 'offer_yearly_amount' => null, 'offer_ends_on' => null])->assertSessionHasNoErrors();

        expect($this->plan->refresh())
            ->offer_monthly_amount->toBeNull()
            ->offer_ends_at->toBeNull();
    });

    it('refuses an offer that is not a real offer', function (array $input, string $field) {
        ($this->save)($input)->assertSessionHasErrors($field);
    })->with([
        'not below the price' => [['offer_monthly_amount' => 450000, 'offer_ends_on' => '2026-04-15'], 'offer_monthly_amount'],
        'for an interval not sold' => [['yearly_amount' => null, 'offer_yearly_amount' => 100000, 'offer_ends_on' => '2026-04-15'], 'offer_yearly_amount'],
        'nothing off' => [['offer_monthly_amount' => 0, 'offer_ends_on' => '2026-04-15'], 'offer_monthly_amount'],
        'a price without a last day' => [['offer_monthly_amount' => 300000], 'offer_ends_on'],
        'a last day without a price' => [['offer_ends_on' => '2026-04-15'], 'offer_monthly_amount'],
        'a last day already gone' => [['offer_monthly_amount' => 300000, 'offer_ends_on' => '2026-03-09'], 'offer_ends_on'],
    ]);
});

describe('the website', function () {
    beforeEach(fn () => config(['platform.registration.mode' => 'open']));

    it('shows the offer price, the plan\'s own price struck through and the offer\'s last day', function () {
        $this->get('http://localhost/pricing')
            ->assertOk()
            ->assertSee('<del>'.e(SitePrice::format(450000, 'BDT', 'en')).'</del>', escape: false)
            ->assertSee(SitePrice::format(300000, 'BDT', 'en'))
            ->assertSee('Offer until 31 March 2026');

        $this->get('http://localhost/bn/pricing')->assertSee('৩১ মার্চ ২০২৬ পর্যন্ত অফার');
    });

    it('says what paying yearly saves', function () {
        $this->plan->forceFill(['offer_monthly_amount' => null, 'offer_ends_at' => null])->save();

        // 45,000 a year against 4,500 × 12 = 54,000: 16.67%, said as 16%.
        $this->get('http://localhost/pricing')->assertSee('Save 16% yearly');
    });
});
