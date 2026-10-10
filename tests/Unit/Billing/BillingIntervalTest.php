<?php

declare(strict_types=1);

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

describe('the end of a period', function () {
    it('is one month or one year after its start, to the second', function (BillingInterval $interval, string $start, string $end) {
        expect($interval->addTo(Carbon::parse($start))->toDateTimeString())->toBe($end);
    })->with([
        'a month' => [BillingInterval::Monthly, '2026-03-10 09:15:30', '2026-04-10 09:15:30'],
        'a month over the new year' => [BillingInterval::Monthly, '2026-12-31 23:59:59', '2027-01-31 23:59:59'],
        'a year' => [BillingInterval::Yearly, '2026-03-10 09:15:30', '2027-03-10 09:15:30'],
        'a year over a leap day' => [BillingInterval::Yearly, '2027-06-01 00:00:00', '2028-06-01 00:00:00'],
    ]);

    it('ends on the last day of a shorter month instead of spilling into the next', function (BillingInterval $interval, string $start, string $end) {
        expect($interval->addTo(Carbon::parse($start))->toDateTimeString())->toBe($end);
    })->with([
        '31 January' => [BillingInterval::Monthly, '2026-01-31 09:00:00', '2026-02-28 09:00:00'],
        '30 January' => [BillingInterval::Monthly, '2026-01-30 09:00:00', '2026-02-28 09:00:00'],
        '29 January' => [BillingInterval::Monthly, '2026-01-29 09:00:00', '2026-02-28 09:00:00'],
        '28 January is not touched' => [BillingInterval::Monthly, '2026-01-28 09:00:00', '2026-02-28 09:00:00'],
        '31 January of a leap year' => [BillingInterval::Monthly, '2028-01-31 09:00:00', '2028-02-29 09:00:00'],
        '31 March' => [BillingInterval::Monthly, '2026-03-31 09:00:00', '2026-04-30 09:00:00'],
        '31 August' => [BillingInterval::Monthly, '2026-08-31 09:00:00', '2026-09-30 09:00:00'],
        '31 July keeps its day' => [BillingInterval::Monthly, '2026-07-31 09:00:00', '2026-08-31 09:00:00'],
        'a year from 29 February' => [BillingInterval::Yearly, '2028-02-29 09:00:00', '2029-02-28 09:00:00'],
        'a year to a 29 February stays on the 28th' => [BillingInterval::Yearly, '2027-02-28 09:00:00', '2028-02-28 09:00:00'],
    ]);

    it('leaves the start it was given alone', function (BillingInterval $interval) {
        $start = Carbon::parse('2026-01-31 09:00:00');
        $immutable = CarbonImmutable::parse('2026-01-31 09:00:00');

        $end = $interval->addTo($start);

        expect($start->toDateTimeString())->toBe('2026-01-31 09:00:00')
            ->and($end)->not->toBe($start)
            ->and($end)->toBeInstanceOf(Carbon::class)
            ->and($interval->addTo($immutable)->toDateTimeString())->toBe($end->toDateTimeString())
            ->and($immutable->toDateTimeString())->toBe('2026-01-31 09:00:00');
    })->with([BillingInterval::Monthly, BillingInterval::Yearly]);

    it('keeps the time zone of the start', function () {
        $start = Carbon::parse('2026-03-10 09:00:00', 'Asia/Dhaka');
        $end = BillingInterval::Monthly->addTo($start);

        expect($end->timezoneName)->toBe('Asia/Dhaka')
            ->and($end->toDateTimeString())->toBe('2026-04-10 09:00:00');
    });
});

it('counts the months in a period, which is what prices of different intervals are compared by', function () {
    expect(BillingInterval::Monthly->months())->toBe(1)
        ->and(BillingInterval::Yearly->months())->toBe(12)
        ->and(array_map(static fn (BillingInterval $interval): string => $interval->value, BillingInterval::cases()))
        ->toBe(['monthly', 'yearly']);
});

describe('subscription status', function () {
    it('lets the workspace be used during a trial, a paid period and the grace period only', function (SubscriptionStatus $status, bool $access) {
        expect($status->grantsAccess())->toBe($access);
    })->with([
        'trialing' => [SubscriptionStatus::Trialing, true],
        'active' => [SubscriptionStatus::Active, true],
        'past due' => [SubscriptionStatus::PastDue, true],
        'expired' => [SubscriptionStatus::Expired, false],
        'canceled' => [SubscriptionStatus::Canceled, false],
    ]);

    it('has a rule for every status', function () {
        expect(array_map(static fn (SubscriptionStatus $status): string => $status->value, SubscriptionStatus::cases()))
            ->toBe(['trialing', 'active', 'past_due', 'expired', 'canceled']);
    });
});

it('treats every payment status but pending as final', function () {
    expect(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Succeeded->isFinal())->toBeTrue()
        ->and(PaymentStatus::Failed->isFinal())->toBeTrue()
        ->and(PaymentStatus::Canceled->isFinal())->toBeTrue();
});

describe('a plan', function () {
    it('names its price per interval, and null for an interval it is not sold for', function () {
        $plan = new Plan(['monthly_amount' => 150000, 'yearly_amount' => 1500000]);
        $monthlyOnly = new Plan(['monthly_amount' => 150000, 'yearly_amount' => null]);
        $yearlyOnly = new Plan(['yearly_amount' => 1500000]);

        expect($plan->amountFor(BillingInterval::Monthly))->toBe(150000)
            ->and($plan->amountFor(BillingInterval::Yearly))->toBe(1500000)
            ->and($monthlyOnly->amountFor(BillingInterval::Monthly))->toBe(150000)
            ->and($monthlyOnly->amountFor(BillingInterval::Yearly))->toBeNull()
            ->and($yearlyOnly->amountFor(BillingInterval::Monthly))->toBeNull()
            ->and($yearlyOnly->amountFor(BillingInterval::Yearly))->toBe(1500000);
    });

    it('reads its amounts as integers, whatever they were given as', function () {
        $plan = new Plan(['monthly_amount' => '150000', 'yearly_amount' => '0']);

        expect($plan->amountFor(BillingInterval::Monthly))->toBe(150000)
            ->and($plan->amountFor(BillingInterval::Yearly))->toBe(0);
    });

    it('offers an interval it has a price for, also when that price is nothing', function () {
        $free = new Plan(['monthly_amount' => 0, 'yearly_amount' => null]);
        $both = new Plan(['monthly_amount' => 150000, 'yearly_amount' => 1500000]);
        $yearlyOnly = new Plan(['yearly_amount' => 1500000]);
        $unsold = new Plan;

        expect($free->offers(BillingInterval::Monthly))->toBeTrue()
            ->and($free->offers(BillingInterval::Yearly))->toBeFalse()
            ->and($free->intervals())->toBe([BillingInterval::Monthly])
            ->and($both->intervals())->toBe([BillingInterval::Monthly, BillingInterval::Yearly])
            ->and($yearlyOnly->offers(BillingInterval::Monthly))->toBeFalse()
            ->and($yearlyOnly->intervals())->toBe([BillingInterval::Yearly])
            ->and($unsold->intervals())->toBe([]);
    });

    it('is free only when no interval it is sold for costs anything', function (?int $monthly, ?int $yearly, bool $free) {
        expect((new Plan(['monthly_amount' => $monthly, 'yearly_amount' => $yearly]))->isFree())->toBe($free);
    })->with([
        'free monthly, not sold yearly' => [0, null, true],
        'free both ways' => [0, 0, true],
        'not sold monthly, free yearly' => [null, 0, true],
        'paid both ways' => [150000, 1500000, false],
        'paid monthly only' => [150000, null, false],
        'paid yearly only' => [null, 1500000, false],
        'free monthly, paid yearly' => [0, 1500000, false],
        'paid monthly, free yearly' => [150000, 0, false],
        'one paisa' => [1, null, false],
    ]);

    it('sets a maximum for the limits it names and none for the others', function () {
        $plan = new Plan(['limits' => ['core.members' => 5, 'sms.messages' => '1000', 'crm.pipelines' => 0]]);

        expect($plan->limit('core.members'))->toBe(5)
            // Stored as JSON, so a number may come back as a string.
            ->and($plan->limit('sms.messages'))->toBe(1000)
            // Zero is a limit - none at all - not "unlimited".
            ->and($plan->limit('crm.pipelines'))->toBe(0)
            ->and($plan->limit('core.branches'))->toBeNull()
            ->and($plan->limit(''))->toBeNull();
    });

    it('has no limits and no modules until it is given some', function () {
        $plan = new Plan;

        expect($plan->limits)->toBe([])
            ->and($plan->modules)->toBe([])
            ->and($plan->limit('core.members'))->toBeNull();
    });

    it('treats a limit stored as null as no limit', function () {
        expect((new Plan(['limits' => ['core.members' => null]]))->limit('core.members'))->toBeNull();
    });
});
