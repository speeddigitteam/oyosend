<?php

declare(strict_types=1);

use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Plan;
use App\Core\Tenancy\TenantContext;
use App\Platform\Enums\PlatformRole;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * The plans workspaces subscribe to, managed from the Super Admin panel.
 * Money is integer minor units: BDT 1,500.00 is 150000.
 *
 * A Super Admin may create and change plans; a Support Admin may only look.
 */
beforeEach(function () {
    Mail::fake();

    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);

    /** A complete, valid plan form. */
    $this->form = fn (array $input = []): array => [
        'key' => 'growth',
        'name' => 'Growth',
        'description' => 'For teams that are growing',
        'currency' => 'BDT',
        'monthly_amount' => 250000,
        'yearly_amount' => 2500000,
        'trial_days' => 14,
        'modules' => [],
        'limits' => [],
        'is_public' => true,
        'is_active' => true,
        'sort_order' => 3,
        ...$input,
    ];

    $this->store = fn (array $input = [], $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from('http://admin.localhost/plans/create')
        ->post('http://admin.localhost/plans', ($this->form)($input));

    $this->update = fn (Plan $plan, array $input = [], $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from("http://admin.localhost/plans/{$plan->id}/edit")
        ->put("http://admin.localhost/plans/{$plan->id}", ($this->form)($input));
});

describe('the list', function () {
    it('lists every plan, active ones first, with how many workspaces are on each', function () {
        $starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'sort_order' => 2, 'monthly_amount' => 150000, 'yearly_amount' => null]);
        $free = $this->createFreePlan(['key' => 'free', 'name' => 'Free', 'sort_order' => 1]);
        $custom = $this->createPlan(['key' => 'custom', 'name' => 'Custom', 'sort_order' => 2, 'is_public' => false, 'trial_days' => 30]);
        $retired = $this->createPlan(['key' => 'retired', 'name' => 'A retired plan', 'sort_order' => 0, 'is_active' => false]);

        $this->subscribeAndPay($this->createTenant('acme'), $starter);
        $this->subscribe($this->createTenant('globex'), $starter, withTrial: false);
        $this->subscribe($this->createTenant('initech'), $free);

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/plans')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($starter): void {
                $page->component('Platform/Pages/Plans/Index')
                    ->where('app.area', 'platform')
                    ->where('canManage', true)
                    ->has('plans', 4);

                $plans = collect($page->toArray()['props']['plans']);

                // Active first, then by sort order, then by name.
                expect($plans->pluck('key')->all())->toBe(['free', 'custom', 'starter', 'retired']);

                $rows = $plans->keyBy('key');

                expect($rows['starter'])->toMatchArray([
                    'id' => $starter->id,
                    'name' => 'Starter',
                    'currency' => 'BDT',
                    'monthlyAmount' => 150000,
                    // Not sold by the year.
                    'yearlyAmount' => null,
                    'trialDays' => 0,
                    'isFree' => false,
                    'isPublic' => true,
                    'isActive' => true,
                    // Whatever state their subscription is in.
                    'subscribers' => 2,
                ])
                    ->and($rows['free'])->toMatchArray(['monthlyAmount' => 0, 'isFree' => true, 'subscribers' => 1])
                    ->and($rows['custom'])->toMatchArray(['isPublic' => false, 'trialDays' => 30, 'subscribers' => 0])
                    ->and($rows['retired'])->toMatchArray(['isActive' => false, 'subscribers' => 0]);
            });

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('is empty before the first plan is made', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/plans')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('plans', []));
    });
});

describe('creating a plan', function () {
    it('shows the form with what a plan can include and cap in this build', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/plans/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Plans/Edit')
                ->where('plan', null)
                // The core module belongs to every workspace: it is not a plan's to give.
                ->where('modules', [
                    ['key' => 'crm', 'name' => 'CRM & Sales'],
                    ['key' => 'projects', 'name' => 'Project Management'],
                    ['key' => 'invoicing', 'name' => 'Invoicing'],
                    ['key' => 'hrm', 'name' => 'HR Management'],
                    ['key' => 'payroll', 'name' => 'Payroll'],
                    ['key' => 'talent', 'name' => 'Talent & Performance'],
                    ['key' => 'automation', 'name' => 'Workflow Automation'],
                    ['key' => 'ai', 'name' => 'AI Assistant'],
                    ['key' => 'communication', 'name' => 'Communication'],
                    ['key' => 'email_marketing', 'name' => 'Email Marketing'],
                    ['key' => 'social', 'name' => 'Social Media'],
                    ['key' => 'meta_ads', 'name' => 'Meta Ads'],
                    ['key' => 'sms_marketing', 'name' => 'SMS Marketing'],
                    ['key' => 'support', 'name' => 'Customer Support'],
                    ['key' => 'whatsapp_marketing', 'name' => 'WhatsApp Business'],
                    ['key' => 'reports', 'name' => 'Reports & Analytics'],
                    ['key' => 'notes', 'name' => 'Notes'],
                    ['key' => 'telephony', 'name' => 'Telephony'],
                    ['key' => 'links', 'name' => 'Links'],
                    ['key' => 'calendar', 'name' => 'Calendar'],
                ])
                ->where('limits', [
                    ['key' => 'core.members', 'kind' => 'gauge'],
                    ['key' => 'projects.active_projects', 'kind' => 'gauge'],
                    ['key' => 'invoicing.invoices_per_month', 'kind' => 'metered'],
                    ['key' => 'hrm.employees', 'kind' => 'gauge'],
                    ['key' => 'payroll.payslips', 'kind' => 'metered'],
                    ['key' => 'talent.job_openings', 'kind' => 'gauge'],
                    ['key' => 'talent.candidates', 'kind' => 'gauge'],
                    ['key' => 'automation.runs', 'kind' => 'metered'],
                    ['key' => 'automation.workflows', 'kind' => 'gauge'],
                    ['key' => 'ai.tokens', 'kind' => 'metered'],
                    ['key' => 'ai.voice_clips', 'kind' => 'metered'],
                    ['key' => 'communication.messages', 'kind' => 'metered'],
                    ['key' => 'email_marketing.campaigns_per_month', 'kind' => 'metered'],
                    ['key' => 'email_marketing.subscribers', 'kind' => 'gauge'],
                    ['key' => 'social.scheduled_posts_per_month', 'kind' => 'metered'],
                    ['key' => 'social.connected_accounts', 'kind' => 'gauge'],
                    ['key' => 'meta_ads.ad_accounts', 'kind' => 'gauge'],
                    ['key' => 'sms_marketing.segments_per_month', 'kind' => 'metered'],
                    ['key' => 'sms_marketing.contacts', 'kind' => 'gauge'],
                    ['key' => 'support.tickets_per_month', 'kind' => 'metered'],
                    ['key' => 'support.agents', 'kind' => 'gauge'],
                    ['key' => 'whatsapp_marketing.conversations_per_month', 'kind' => 'metered'],
                    ['key' => 'whatsapp_marketing.agents', 'kind' => 'gauge'],
                    ['key' => 'reports.saved_reports', 'kind' => 'gauge'],
                ])
                ->where('currencies', config('platform.currencies')));
    });

    it('offers the optional modules that are registered', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/plans/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('modules', [
                ['key' => 'crm', 'name' => 'CRM & Sales'],
                ['key' => 'projects', 'name' => 'Project Management'],
                ['key' => 'invoicing', 'name' => 'Invoicing'],
                ['key' => 'hrm', 'name' => 'HR Management'],
                ['key' => 'payroll', 'name' => 'Payroll'],
                ['key' => 'talent', 'name' => 'Talent & Performance'],
                ['key' => 'automation', 'name' => 'Workflow Automation'],
                ['key' => 'ai', 'name' => 'AI Assistant'],
                ['key' => 'communication', 'name' => 'Communication'],
                ['key' => 'email_marketing', 'name' => 'Email Marketing'],
                ['key' => 'social', 'name' => 'Social Media'],
                ['key' => 'meta_ads', 'name' => 'Meta Ads'],
                ['key' => 'sms_marketing', 'name' => 'SMS Marketing'],
                ['key' => 'support', 'name' => 'Customer Support'],
                ['key' => 'whatsapp_marketing', 'name' => 'WhatsApp Business'],
                ['key' => 'reports', 'name' => 'Reports & Analytics'],
                ['key' => 'notes', 'name' => 'Notes'],
                ['key' => 'telephony', 'name' => 'Telephony'],
                ['key' => 'links', 'name' => 'Links'],
                ['key' => 'calendar', 'name' => 'Calendar'],
            ]));
    });

    it('creates a plan and records it in the platform\'s trail', function () {
        ($this->store)(['modules' => ['crm'], 'limits' => ['core.members' => '25']])
            ->assertRedirect('http://admin.localhost/plans')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $plan = Plan::query()->where('key', 'growth')->sole();

        expect($plan->name)->toBe('Growth')
            ->and($plan->description)->toBe('For teams that are growing')
            ->and($plan->currency)->toBe('BDT')
            ->and($plan->monthly_amount)->toBe(250000)
            ->and($plan->yearly_amount)->toBe(2500000)
            ->and($plan->trial_days)->toBe(14)
            ->and($plan->modules)->toBe(['crm'])
            // Stored as a number, whatever the form sent it as.
            ->and($plan->limits)->toBe(['core.members' => 25])
            ->and($plan->is_public)->toBeTrue()
            ->and($plan->is_active)->toBeTrue()
            ->and($plan->sort_order)->toBe(3);

        $entry = $this->auditTrail(null, 'plan.created')->sole();

        expect($entry->tenant_id)->toBeNull()
            ->and($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_id)->toBe($plan->id)
            ->and($entry->subject_label)->toBe('Growth')
            ->and($entry->properties['attributes'])->toMatchArray(['key' => 'growth', 'name' => 'Growth', 'monthly_amount' => 250000]);

        // A workspace can be put on it straight away.
        $acme = $this->createTenant('acme');
        $this->subscribe($acme, $plan);

        expect($this->subscriptionOf($acme)->status)->toBe(SubscriptionStatus::Trialing);
    });

    it('tells "not sold for this interval" from "free"', function () {
        ($this->store)(['key' => 'monthly-only', 'monthly_amount' => 150000, 'yearly_amount' => null])->assertSessionHasNoErrors();
        ($this->store)(['key' => 'yearly-only', 'monthly_amount' => '', 'yearly_amount' => 1500000])->assertSessionHasNoErrors();
        ($this->store)(['key' => 'free', 'monthly_amount' => 0, 'yearly_amount' => null])->assertSessionHasNoErrors();

        $plans = Plan::query()->get()->keyBy('key');

        expect($plans['monthly-only']->monthly_amount)->toBe(150000)
            ->and($plans['monthly-only']->yearly_amount)->toBeNull()
            ->and($plans['monthly-only']->isFree())->toBeFalse()
            ->and($plans['yearly-only']->monthly_amount)->toBeNull()
            ->and($plans['yearly-only']->yearly_amount)->toBe(1500000)
            ->and($plans['free']->monthly_amount)->toBe(0)
            ->and($plans['free']->isFree())->toBeTrue();
    });

    it('treats an empty limit as "unlimited" and stores nothing for it', function (mixed $limits) {
        ($this->store)(['limits' => $limits])->assertSessionHasNoErrors();

        expect(Plan::query()->where('key', 'growth')->sole()->limits)->toBe([]);
    })->with([
        'no limits at all' => [[]],
        'an empty field' => [['core.members' => '']],
        'a null' => [['core.members' => null]],
    ]);

    it('accepts zero as a limit: nothing of that is allowed', function () {
        ($this->store)(['limits' => ['core.members' => 0]])->assertSessionHasNoErrors();

        expect(Plan::query()->where('key', 'growth')->sole()->limits)->toBe(['core.members' => 0]);
    });

    it('leaves out a module that is named twice', function () {
        ($this->store)(['modules' => ['crm', 'crm']])->assertSessionHasNoErrors();

        expect(Plan::query()->where('key', 'growth')->sole()->modules)->toBe(['crm']);
    });
});

describe('validation', function () {
    it('wants a key of lower-case letters, digits, hyphens and underscores that starts with a letter', function (string $key) {
        ($this->store)(['key' => $key])
            ->assertRedirect('http://admin.localhost/plans/create')
            ->assertSessionHasErrors('key');

        expect(Plan::query()->count())->toBe(0);
    })->with([
        'empty' => [''],
        'capitals' => ['Growth'],
        'a leading digit' => ['2nd-plan'],
        'a leading hyphen' => ['-growth'],
        'a space' => ['growth plan'],
        'a dot' => ['growth.plan'],
        'other characters' => ['growth!'],
        'too long' => [str_repeat('a', 41)],
    ]);

    it('accepts hyphens, underscores and digits inside a key', function () {
        ($this->store)(['key' => 'growth_2026-q1'])->assertSessionHasNoErrors();

        expect(Plan::query()->where('key', 'growth_2026-q1')->exists())->toBeTrue();
    });

    it('wants a key no other plan has', function () {
        $existing = $this->createPlan(['key' => 'growth']);
        $other = $this->createPlan(['key' => 'starter']);

        ($this->store)()->assertSessionHasErrors('key');

        // An archived plan keeps its key as well.
        $existing->update(['is_active' => false]);
        ($this->store)()->assertSessionHasErrors('key');

        // A plan may keep its own key when it is saved again, but not take another's.
        ($this->update)($existing, ['name' => 'Growth, renamed'])->assertSessionHasNoErrors();
        ($this->update)($other)->assertSessionHasErrors('key');

        expect(Plan::query()->count())->toBe(2)
            ->and($existing->refresh()->name)->toBe('Growth, renamed')
            ->and($other->refresh()->key)->toBe('starter');
    });

    it('wants a price for at least one interval', function () {
        ($this->store)(['monthly_amount' => null, 'yearly_amount' => null])
            ->assertSessionHasErrors(['monthly_amount', 'yearly_amount']);

        ($this->store)(['monthly_amount' => '', 'yearly_amount' => ''])
            ->assertSessionHasErrors(['monthly_amount', 'yearly_amount']);

        expect(Plan::query()->count())->toBe(0);
    });

    it('takes prices as whole minor units only', function (string $field, mixed $value) {
        ($this->store)([$field => $value])->assertSessionHasErrors($field);

        expect(Plan::query()->count())->toBe(0);
    })->with([
        'a negative monthly price' => ['monthly_amount', -1],
        'a monthly price with decimals' => ['monthly_amount', '1500.50'],
        'a float' => ['monthly_amount', 1500.5],
        'a monthly price that is not a number' => ['monthly_amount', 'free'],
        'a monthly price beyond the limit' => ['monthly_amount', 100000000000],
        'a negative yearly price' => ['yearly_amount', -100],
        'a yearly price with decimals' => ['yearly_amount', '15000.5'],
    ]);

    it('validates the rest of the form', function (array $input, string $field) {
        ($this->store)($input)->assertSessionHasErrors($field);

        expect(Plan::query()->count())->toBe(0);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'a name that is too long' => [['name' => str_repeat('a', 81)], 'name'],
        'a description that is too long' => [['description' => str_repeat('a', 256)], 'description'],
        'no currency' => [['currency' => ''], 'currency'],
        'a currency the platform does not use' => [['currency' => 'XXX'], 'currency'],
        'no trial length' => [['trial_days' => ''], 'trial_days'],
        'a negative trial' => [['trial_days' => -1], 'trial_days'],
        'a trial of more than a year' => [['trial_days' => 366], 'trial_days'],
        'a trial in half days' => [['trial_days' => '1.5'], 'trial_days'],
        'modules that are not a list' => [['modules' => 'crm'], 'modules'],
        'limits that are not a map' => [['limits' => 'core.members'], 'limits'],
        'public or not left unsaid' => [['is_public' => null], 'is_public'],
        'public that is not yes or no' => [['is_public' => 'maybe'], 'is_public'],
        'active that is not yes or no' => [['is_active' => 'archived'], 'is_active'],
        'no sort order' => [['sort_order' => ''], 'sort_order'],
        'a sort order out of range' => [['sort_order' => 1000], 'sort_order'],
    ]);

    it('only includes optional modules that are registered', function (string $module) {
        ($this->store)(['modules' => ['crm', $module]])->assertSessionHasErrors('modules.1');

        expect(Plan::query()->count())->toBe(0);
    })->with([
        // Core modules belong to every workspace; a plan neither gives nor withholds them.
        'the core module' => ['core'],
        'a module this build does not have' => ['retired_app'],
    ]);

    it('does not include a module that is not registered, even one that exists elsewhere', function () {
        ($this->store)(['modules' => ['retired_app']])->assertSessionHasErrors('modules.0');

        expect(Plan::query()->count())->toBe(0);
    });

    it('only limits what is registered, with whole numbers of zero or more', function (array $limits) {
        ($this->store)(['limits' => $limits])
            ->assertRedirect('http://admin.localhost/plans/create')
            ->assertSessionHasErrors(['limits' => 'A limit must be a whole number of zero or more.']);

        expect(Plan::query()->count())->toBe(0);
    })->with([
        'a limit nobody registered' => [['sms.messages' => 100]],
        'a negative number' => [['core.members' => -1]],
        'a fraction' => [['core.members' => '2.5']],
        'words' => [['core.members' => 'unlimited']],
        'a good one next to a bad one' => [['core.members' => 10, 'crm.leads' => 50]],
    ]);
});

describe('editing a plan', function () {
    beforeEach(function () {
        $this->starter = $this->createPlan([
            'key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000, 'limits' => ['core.members' => 5],
        ]);
    });

    it('shows the plan in the form, with how many workspaces are on it', function () {
        $this->subscribeAndPay($this->createTenant('acme'), $this->starter);

        $this->actingAsPlatformUser($this->admin)
            ->get("http://admin.localhost/plans/{$this->starter->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Plans/Edit')
                ->where('plan.id', $this->starter->id)
                ->where('plan.key', 'starter')
                ->where('plan.name', 'Starter')
                ->where('plan.monthlyAmount', 150000)
                ->where('plan.yearlyAmount', 1500000)
                ->where('plan.limits', ['core.members' => 5])
                ->where('plan.modules', [])
                ->where('plan.subscribers', 1)
                ->where('limits', [
                    ['key' => 'core.members', 'kind' => 'gauge'],
                    ['key' => 'projects.active_projects', 'kind' => 'gauge'],
                    ['key' => 'invoicing.invoices_per_month', 'kind' => 'metered'],
                    ['key' => 'hrm.employees', 'kind' => 'gauge'],
                    ['key' => 'payroll.payslips', 'kind' => 'metered'],
                    ['key' => 'talent.job_openings', 'kind' => 'gauge'],
                    ['key' => 'talent.candidates', 'kind' => 'gauge'],
                    ['key' => 'automation.runs', 'kind' => 'metered'],
                    ['key' => 'automation.workflows', 'kind' => 'gauge'],
                    ['key' => 'ai.tokens', 'kind' => 'metered'],
                    ['key' => 'ai.voice_clips', 'kind' => 'metered'],
                    ['key' => 'communication.messages', 'kind' => 'metered'],
                    ['key' => 'email_marketing.campaigns_per_month', 'kind' => 'metered'],
                    ['key' => 'email_marketing.subscribers', 'kind' => 'gauge'],
                    ['key' => 'social.scheduled_posts_per_month', 'kind' => 'metered'],
                    ['key' => 'social.connected_accounts', 'kind' => 'gauge'],
                    ['key' => 'meta_ads.ad_accounts', 'kind' => 'gauge'],
                    ['key' => 'sms_marketing.segments_per_month', 'kind' => 'metered'],
                    ['key' => 'sms_marketing.contacts', 'kind' => 'gauge'],
                    ['key' => 'support.tickets_per_month', 'kind' => 'metered'],
                    ['key' => 'support.agents', 'kind' => 'gauge'],
                    ['key' => 'whatsapp_marketing.conversations_per_month', 'kind' => 'metered'],
                    ['key' => 'whatsapp_marketing.agents', 'kind' => 'gauge'],
                    ['key' => 'reports.saved_reports', 'kind' => 'gauge'],
                ])
                ->has('currencies'));
    });

    it('saves the changes and records what was changed, from what to what', function () {
        ($this->update)($this->starter, [
            'key' => 'starter',
            'name' => 'Starter Plus',
            'description' => null,
            'monthly_amount' => 180000,
            'yearly_amount' => null,
            'trial_days' => 7,
            'limits' => ['core.members' => 8],
            'is_public' => false,
            'sort_order' => 9,
        ])
            ->assertRedirect('http://admin.localhost/plans')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $this->starter->refresh();

        expect($this->starter->name)->toBe('Starter Plus')
            ->and($this->starter->description)->toBeNull()
            ->and($this->starter->monthly_amount)->toBe(180000)
            ->and($this->starter->yearly_amount)->toBeNull()
            ->and($this->starter->trial_days)->toBe(7)
            ->and($this->starter->limits)->toBe(['core.members' => 8])
            ->and($this->starter->is_public)->toBeFalse()
            ->and($this->starter->sort_order)->toBe(9);

        $entry = $this->auditTrail(null, 'plan.updated')->sole();

        expect($entry->tenant_id)->toBeNull()
            ->and($entry->actor_type)->toBe('platform_user')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_id)->toBe($this->starter->id)
            ->and($entry->properties['old'])->toMatchArray(['name' => 'Starter', 'monthly_amount' => 150000, 'yearly_amount' => 1500000])
            ->and($entry->properties['attributes'])->toMatchArray(['name' => 'Starter Plus', 'monthly_amount' => 180000, 'yearly_amount' => null])
            // What did not change is not in the entry.
            ->and($entry->properties['attributes'])->not->toHaveKey('key')
            ->and($entry->properties['attributes'])->not->toHaveKey('currency');
    });

    it('writes no audit entry when nothing changed', function () {
        ($this->update)($this->starter, [
            'key' => 'starter',
            'name' => 'Starter',
            'description' => null,
            'monthly_amount' => 150000,
            'yearly_amount' => 1500000,
            'trial_days' => 0,
            'limits' => ['core.members' => 5],
            'sort_order' => 0,
        ])->assertSessionHasNoErrors();

        expect($this->auditTrail(null, 'plan.updated'))->toHaveCount(0);
    });

    it('keeps the price a workspace took the plan at when the plan gets dearer', function () {
        $acme = $this->createTenant('acme');
        $this->subscribeAndPay($acme, $this->starter);

        ($this->update)($this->starter, ['key' => 'starter', 'monthly_amount' => 999900])->assertSessionHasNoErrors();

        expect($this->starter->refresh()->monthly_amount)->toBe(999900)
            ->and($this->subscriptionOf($acme)->amount)->toBe(150000);
    });

    it('archives a plan without taking it away from the workspaces on it', function () {
        $acme = $this->createTenant('acme');
        $this->subscribeAndPay($acme, $this->starter);

        ($this->update)($this->starter, ['key' => 'starter', 'name' => 'Starter', 'is_active' => false])->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($acme);

        expect($this->starter->refresh()->is_active)->toBeFalse()
            // Never deleted: the subscription and its invoices still point at it.
            ->and(Plan::query()->whereKey($this->starter->id)->exists())->toBeTrue()
            ->and($subscription->plan->is($this->starter))->toBeTrue()
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->grantsAccess())->toBeTrue()
            // No longer on offer to anybody else.
            ->and(Plan::query()->offered()->pluck('key')->all())->not->toContain('starter');

        // Still listed in the panel, as archived, with its workspace.
        $this->get('http://admin.localhost/plans')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plans.0.key', 'starter')
                ->where('plans.0.isActive', false)
                ->where('plans.0.subscribers', 1));

        // And not among the plans a new workspace can be created on.
        $this->get('http://admin.localhost/tenants/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('plans', []));
    });

    it('validates an edit like a new plan', function () {
        ($this->update)($this->starter, ['key' => 'Starter Plan', 'monthly_amount' => null, 'yearly_amount' => null, 'limits' => ['core.members' => -5]])
            ->assertRedirect("http://admin.localhost/plans/{$this->starter->id}/edit")
            ->assertSessionHasErrors(['key', 'monthly_amount', 'yearly_amount']);

        ($this->update)($this->starter, ['key' => 'starter', 'limits' => ['core.members' => -5]])->assertSessionHasErrors('limits');

        expect($this->starter->refresh()->getAttributes())->toMatchArray(['key' => 'starter', 'monthly_amount' => 150000])
            ->and($this->starter->limits)->toBe(['core.members' => 5])
            ->and($this->auditTrail(null, 'plan.updated'))->toHaveCount(0);
    });

    it('answers 404 for a plan that does not exist', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->get('http://admin.localhost/plans/01ARZ3NDEKTSV4RRFFQ69G5FAV/edit')->assertNotFound();
        $this->put('http://admin.localhost/plans/01ARZ3NDEKTSV4RRFFQ69G5FAV', ($this->form)())->assertNotFound();
    });

    it('has no way to delete a plan', function () {
        $this->actingAsPlatformUser($this->admin)
            ->delete("http://admin.localhost/plans/{$this->starter->id}")
            ->assertStatus(405);

        expect(Plan::query()->whereKey($this->starter->id)->exists())->toBeTrue();
    });
});

describe('who may do it', function () {
    it('lets a support admin look at the plans, and nothing more', function () {
        $plan = $this->createPlan(['key' => 'starter', 'name' => 'Starter']);

        $this->actingAsPlatformUser($this->support);

        $this->get('http://admin.localhost/plans')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->has('plans', 1));

        $this->get('http://admin.localhost/plans/create')->assertForbidden();
        $this->get("http://admin.localhost/plans/{$plan->id}/edit")->assertForbidden();
        ($this->store)(as: $this->support)->assertForbidden();
        ($this->update)($plan, ['name' => 'Taken over'], $this->support)->assertForbidden();

        expect(Plan::query()->count())->toBe(1)
            ->and($plan->refresh()->name)->toBe('Starter')
            ->and($this->auditTrail(null, 'plan.updated'))->toHaveCount(0);
    });

    it('lets a super admin do all of it', function () {
        $plan = $this->createPlan(['key' => 'starter']);

        $this->actingAsPlatformUser($this->admin);

        $this->get('http://admin.localhost/plans')->assertOk();
        $this->get('http://admin.localhost/plans/create')->assertOk();
        $this->get("http://admin.localhost/plans/{$plan->id}/edit")->assertOk();
        ($this->store)()->assertRedirect('http://admin.localhost/plans');
        ($this->update)($plan, ['key' => 'starter', 'name' => 'Renamed'])->assertRedirect('http://admin.localhost/plans');

        expect(Plan::query()->count())->toBe(2)
            ->and($plan->refresh()->name)->toBe('Renamed');
    });

    it('is not for guests, workspace owners or accounts without two-factor', function (string $method, string $path) {
        $plan = $this->createPlan(['key' => 'starter', 'name' => 'Starter']);
        $acme = $this->createTenant('acme');
        $url = 'http://admin.localhost'.str_replace('{id}', $plan->id, $path);

        $this->call($method, $url, ($this->form)())->assertRedirect('http://admin.localhost/login');
        $this->actingAsOwner($acme)->call($method, $url, ($this->form)())->assertRedirect('http://admin.localhost/login');

        $this->startNewBrowserSession()
            ->actingAsPlatformUser($this->createPlatformUser(twoFactor: false))
            ->call($method, $url, ($this->form)())
            ->assertRedirect('http://admin.localhost/two-factor/setup');

        expect(Plan::query()->count())->toBe(1)
            ->and($plan->refresh()->name)->toBe('Starter');
    })->with([
        ['GET', '/plans'],
        ['GET', '/plans/create'],
        ['POST', '/plans'],
        ['GET', '/plans/{id}/edit'],
        ['PUT', '/plans/{id}'],
    ]);
});
