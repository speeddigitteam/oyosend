<?php

declare(strict_types=1);

use App\Core\Billing\Models\Plan;
use App\Core\Settings\PlatformSettings;
use Inertia\Testing\AssertableInertia;

/*
 * What the landing page is given about signing up. Prices are only sent
 * where someone can act on them: with registration closed there is nothing
 * to create and nothing to price.
 */
beforeEach(function () {
    config(['platform.registration.mode' => 'open']);

    $this->createPlan(['key' => 'business', 'name' => 'Business', 'sort_order' => 3, 'monthly_amount' => 450000, 'yearly_amount' => 4500000]);
    $this->createPlan([
        'key' => 'starter',
        'name' => 'Starter',
        'description' => 'For a small team.',
        'sort_order' => 2,
        'trial_days' => 14,
        'modules' => ['crm'],
        'limits' => ['core.members' => 5],
    ]);
    $this->createFreePlan(['key' => 'free', 'name' => 'Free', 'sort_order' => 1]);

    // Never offered: assigned from the Super Admin panel only, or no longer sold.
    $this->createPlan(['key' => 'partner', 'is_public' => false]);
    $this->createPlan(['key' => 'legacy', 'is_active' => false]);

    $this->setMode = fn (string $mode) => app(PlatformSettings::class)->put(['registration.mode' => $mode]);
});

it('offers sign-up and shows the plans on offer while the platform accepts sign-ups', function (string $mode) {
    ($this->setMode)($mode);

    $this->get('http://localhost/')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('Core/Pages/Central/Welcome')
                ->where('domain', 'localhost')
                ->where('registrationOpen', true)
                ->has('plans', 3);

            $plans = collect($page->toArray()['props']['plans']);

            // Active and public only, in the operator's order.
            expect($plans->pluck('key')->all())->toBe(['free', 'starter', 'business'])
                ->and($plans[0])->toMatchArray([
                    'name' => 'Free',
                    'currency' => 'BDT',
                    'monthlyAmount' => 0,
                    'yearlyAmount' => null,
                    'trialDays' => 0,
                    'isFree' => true,
                ])
                ->and($plans[1])->toMatchArray([
                    'name' => 'Starter',
                    'description' => 'For a small team.',
                    'currency' => 'BDT',
                    'monthlyAmount' => 150000,
                    'yearlyAmount' => 1500000,
                    'trialDays' => 14,
                    'modules' => ['crm'],
                    'limits' => ['core.members' => 5],
                    'isFree' => false,
                ])
                ->and($plans[2])->toMatchArray(['name' => 'Business', 'monthlyAmount' => 450000, 'yearlyAmount' => 4500000, 'isFree' => false]);
        });
})->with(['open', 'approval']);

it('offers neither sign-up nor prices while registration is closed', function () {
    ($this->setMode)('closed');

    $this->get('http://localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Central/Welcome')
            ->where('domain', 'localhost')
            ->where('registrationOpen', false)
            ->where('plans', []));
});

it('offers sign-up without prices where no plan is on offer', function () {
    Plan::query()->update(['is_active' => false]);

    $this->get('http://localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registrationOpen', true)
            ->where('plans', []));
});

it('follows the platform default until an operator changes the mode', function () {
    config(['platform.registration.mode' => 'closed']);

    $this->get('http://localhost/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registrationOpen', false)->where('plans', []));

    ($this->setMode)('open');

    $this->get('http://localhost/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registrationOpen', true)->has('plans', 3));
});

it('tells the browser about the sign-up routes of the central site', function () {
    $html = $this->get('http://localhost/')->getContent();

    expect($html)->toContain('central.register.store', 'central.register.sent', 'central.register.confirm', 'central.register.complete')
        ->not->toContain('core.billing.index', 'core.members.index', 'platform.dashboard');
});
