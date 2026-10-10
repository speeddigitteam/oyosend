<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Subscription;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Platform\Enums\PlatformRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * What an operator does to a workspace as a whole from the Super Admin panel:
 * find it, look into it, create it, approve it, suspend it, bring it back.
 *
 * A Super Admin may do all of it; a Support Admin may only look.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->addMember($this->acme, Role::ADMIN);
    $this->addMember($this->acme, Role::EMPLOYEE);

    /** Create a workspace from the panel, as the Super Admin unless told otherwise. */
    $this->createWorkspace = fn (array $input = [], $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from('http://admin.localhost/tenants/create')
        ->post('http://admin.localhost/tenants', [
            'name' => 'Nova Traders',
            'slug' => 'nova',
            'owner_name' => 'Nadia Islam',
            'owner_email' => 'nadia@nova.test',
            'locale' => 'en',
            ...$input,
        ]);

    /** The emails sent so far, oldest first. */
    $this->emails = fn () => app('mailer')->getSymfonyTransport()->messages()->map->getOriginalMessage();

    $this->act = fn (Tenant $tenant, string $action, array $input = [], $as = null): TestResponse => $this
        ->actingAsPlatformUser($as ?? $this->admin)
        ->from("http://admin.localhost/tenants/{$tenant->id}")
        ->post("http://admin.localhost/tenants/{$tenant->id}/{$action}", $input);
});

describe('the directory', function () {
    it('shows the plan of each workspace and where its subscription stands', function () {
        $globex = $this->createTenant('globex');
        $this->subscribe($this->acme, $this->createPlan(['name' => 'Business', 'trial_days' => 14]));
        $this->subscribeAndPay($globex, $this->createPlan(['name' => 'Starter']));
        $this->createTenant('initech');

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/tenants')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Platform/Pages/Tenants/Index')->where('canManage', true);

                $rows = collect($page->toArray()['props']['tenants']['data'])->keyBy('slug');

                expect($rows['acme'])->toMatchArray(['plan' => 'Business', 'subscriptionStatus' => 'trialing'])
                    ->and($rows['globex'])->toMatchArray(['plan' => 'Starter', 'subscriptionStatus' => 'active'])
                    // No subscription: nothing to show, not a made-up status.
                    ->and($rows['initech'])->toMatchArray(['plan' => null, 'subscriptionStatus' => null]);
            });

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('tells a support admin that the directory is theirs to read only', function () {
        $this->actingAsPlatformUser($this->support)
            ->get('http://admin.localhost/tenants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->where('tenants.total', 1));
    });
});

describe('creating a workspace', function () {
    it('shows the form with the plans that can be given', function () {
        $public = $this->createPlan(['name' => 'Starter', 'sort_order' => 1]);
        $hidden = $this->createPlan(['name' => 'Enterprise', 'is_public' => false, 'sort_order' => 2]);
        $this->createPlan(['name' => 'Retired', 'is_active' => false]);

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/tenants/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Tenants/Create')
                ->where('domain', 'localhost')
                // Hidden plans can be given by an operator; archived ones by nobody.
                ->where('plans', fn ($plans): bool => collect($plans)->pluck('id')->all() === [$public->id, $hidden->id])
                ->where('plans.1.isPublic', false));
    });

    it('creates an active workspace with a new owner account, who is mailed a link to choose a password', function () {
        $response = ($this->createWorkspace)(['owner_email' => '  Nadia@Nova.TEST ', 'name' => '  Nova Traders ', 'locale' => 'bn']);

        $nova = Tenant::query()->where('slug', 'nova')->sole();

        $response->assertRedirect("http://admin.localhost/tenants/{$nova->id}")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $owner = User::query()->where('email', 'nadia@nova.test')->sole();

        expect($nova->name)->toBe('Nova Traders')
            ->and($nova->status)->toBe(TenantStatus::Active)
            ->and($nova->locale)->toBe('bn')
            // Made by an operator: the first-run setup of a sign-up is not asked for.
            ->and($nova->isOnboarded())->toBeTrue()
            ->and($owner->name)->toBe('Nadia Islam')
            ->and($owner->locale)->toBe('bn')
            ->and($this->ownerOf($nova)->is($owner))->toBeTrue()
            ->and($this->membershipOf($nova, $owner)->is_owner)->toBeTrue()
            // No plan was asked for.
            ->and(Subscription::forTenant($nova)->exists())->toBeFalse()
            // Nobody knows the password the account was created with.
            ->and(Hash::check('password', $owner->password))->toBeFalse();

        // Recorded twice: for the platform, and where the workspace's own people can read it.
        $platform = $this->auditTrail(null, 'platform.workspace.created')->sole();
        $workspace = $this->auditTrail($nova, 'workspace.created')->sole();

        expect($platform->tenant_id)->toBeNull()
            ->and($platform->actor_type)->toBe('platform_user')
            ->and($platform->actor_id)->toBe($this->admin->id)
            ->and($platform->subject_type)->toBe('tenant')
            ->and($platform->subject_id)->toBe($nova->id)
            ->and($platform->properties)->toEqual(['owner' => 'nadia@nova.test', 'plan' => null])
            ->and($workspace->actor_id)->toBe($this->admin->id)
            ->and($workspace->actor_label)->toBe($this->admin->email);

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('mails a set-password link that works on the workspace\'s own host', function () {
        ($this->createWorkspace)();

        $nova = Tenant::query()->where('slug', 'nova')->sole();
        $message = ($this->emails)()->sole();

        expect($message->getTo()[0]->getAddress())->toBe('nadia@nova.test')
            ->and($message->getSubject())->toBe('Nova Traders is ready')
            ->and($message->getHtmlBody())->toContain('nova.localhost');

        expect(preg_match('#http://nova\.localhost/reset-password/([A-Za-z0-9]+)\?email=nadia%40nova\.test#', (string) $message->getHtmlBody(), $match))->toBe(1);

        // Only a hash of the token is stored.
        expect(DB::table('password_reset_tokens')->where('email', 'nadia@nova.test')->value('token'))->not->toBe($match[1]);

        // The owner opens the link in their own browser.
        $this->startNewBrowserSession();

        $this->get($match[0])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Auth/ResetPassword')
                ->where('token', $match[1])
                ->where('email', 'nadia@nova.test')
                ->where('tenant.slug', 'nova'));

        $this->post('http://nova.localhost/reset-password', [
            'token' => $match[1],
            'email' => 'nadia@nova.test',
            'password' => 'chosen-by-nadia-1',
            'password_confirmation' => 'chosen-by-nadia-1',
        ])->assertRedirect('http://nova.localhost/login')->assertSessionHasNoErrors();

        $this->post('http://nova.localhost/login', ['email' => 'nadia@nova.test', 'password' => 'chosen-by-nadia-1'])
            ->assertRedirect('http://nova.localhost');

        $this->assertAuthenticatedAs($this->ownerOf($nova), 'web');

        $this->get('http://nova.localhost/')->assertOk();
    });

    it('makes an existing account the owner without creating a second one or touching its password', function () {
        $existing = User::factory()->create([
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.test',
            'password' => 'their-own-password',
        ]);
        $accounts = User::query()->count();

        ($this->createWorkspace)(['slug' => 'beta', 'name' => 'Beta Ltd', 'owner_name' => 'Somebody Else', 'owner_email' => 'RAHIM@example.test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $beta = Tenant::query()->where('slug', 'beta')->sole();
        $existing->refresh();

        expect(User::query()->count())->toBe($accounts)
            ->and($this->ownerOf($beta)->is($existing))->toBeTrue()
            // The account is the person's own: the form does not rename it or reset it.
            ->and($existing->name)->toBe('Rahim Uddin')
            ->and(Hash::check('their-own-password', $existing->password))->toBeTrue()
            ->and(DB::table('password_reset_tokens')->where('email', 'rahim@example.test')->exists())->toBeFalse();

        // They are told where to sign in, with the password they already have.
        $message = ($this->emails)()->sole();

        expect($message->getTo()[0]->getAddress())->toBe('rahim@example.test')
            ->and($message->getHtmlBody())->toContain('http://beta.localhost/login')
            ->not->toContain('reset-password');
    });

    it('starts the workspace on a plan when one is chosen', function (string $case) {
        $plan = match ($case) {
            'with a trial' => $this->createPlan(['key' => 'pro', 'trial_days' => 14, 'monthly_amount' => 200000, 'yearly_amount' => 2000000]),
            'without a trial' => $this->createPlan(['key' => 'pro', 'monthly_amount' => 200000, 'yearly_amount' => 2000000]),
            'free' => $this->createFreePlan(['key' => 'pro']),
            'not public' => $this->createPlan(['key' => 'pro', 'is_public' => false, 'trial_days' => 14]),
        };

        ($this->createWorkspace)(['plan_id' => $plan->id, 'interval' => 'monthly'])->assertRedirect()->assertSessionHasNoErrors();

        $nova = Tenant::query()->where('slug', 'nova')->sole();
        $subscription = $this->subscriptionOf($nova);

        expect($subscription->plan->is($plan))->toBeTrue()
            ->and($subscription->interval)->toBe(BillingInterval::Monthly)
            ->and($subscription->status)->toBe(match ($case) {
                'with a trial', 'not public' => SubscriptionStatus::Trialing,
                // Invoiced at once; the workspace has the grace period to pay.
                'without a trial' => SubscriptionStatus::PastDue,
                'free' => SubscriptionStatus::Active,
            })
            ->and($this->invoicesOf($nova))->toHaveCount($case === 'without a trial' ? 1 : 0)
            // The owner hears about the workspace - and about the invoice, when there is one to pay.
            ->and(($this->emails)()->map->getSubject()->all())->toBe($case === 'without a trial'
                ? ["Invoice {$this->invoicesOf($nova)->first()?->number} for Nova Traders", 'Nova Traders is ready']
                : ['Nova Traders is ready'])
            ->and($this->auditTrail(null, 'platform.workspace.created')->sole()->properties)->toEqual(['owner' => 'nadia@nova.test', 'plan' => 'pro'])
            // Started by the operator, and said so in the workspace's own trail.
            ->and($this->auditTrail($nova, 'billing.subscription_started')->sole()->actor_id)->toBe($this->admin->id);
    })->with(['with a trial', 'without a trial', 'free', 'not public']);

    it('takes the billing interval that was chosen, or one the plan is sold for', function () {
        $plan = $this->createPlan(['trial_days' => 7, 'monthly_amount' => 100000, 'yearly_amount' => 1000000]);
        $monthlyOnly = $this->createPlan(['trial_days' => 7, 'monthly_amount' => 100000, 'yearly_amount' => null]);

        ($this->createWorkspace)(['slug' => 'yearly-co', 'owner_email' => 'a@example.test', 'plan_id' => $plan->id, 'interval' => 'yearly']);
        ($this->createWorkspace)(['slug' => 'fallback-co', 'owner_email' => 'b@example.test', 'plan_id' => $monthlyOnly->id, 'interval' => 'yearly']);
        ($this->createWorkspace)(['slug' => 'unsaid-co', 'owner_email' => 'c@example.test', 'plan_id' => $plan->id]);

        $of = fn (string $slug) => $this->subscriptionOf(Tenant::query()->where('slug', $slug)->sole());

        expect($of('yearly-co')->interval)->toBe(BillingInterval::Yearly)
            ->and($of('yearly-co')->amount)->toBe(1000000)
            // Not sold yearly: the workspace is not refused, it gets what the plan has.
            ->and($of('fallback-co')->interval)->toBe(BillingInterval::Monthly)
            ->and($of('unsaid-co')->interval)->toBe(BillingInterval::Monthly);
    });

    it('refuses an address that is malformed, reserved or taken', function (string $slug) {
        $this->createTenant('gone')->delete();
        $workspaces = Tenant::withTrashed()->count();

        ($this->createWorkspace)(['slug' => $slug])
            ->assertRedirect('http://admin.localhost/tenants/create')
            ->assertSessionHasErrors('slug');

        expect(Tenant::withTrashed()->count())->toBe($workspaces)
            ->and(User::query()->where('email', 'nadia@nova.test')->exists())->toBeFalse()
            ->and(($this->emails)())->toHaveCount(0)
            ->and($this->auditTrail(null, 'platform.workspace.created'))->toHaveCount(0);
    })->with([
        'too short' => ['ab'],
        'a space' => ['nova traders'],
        'an underscore' => ['nova_traders'],
        'a leading hyphen' => ['-nova'],
        'a trailing hyphen' => ['nova-'],
        'a dot' => ['nova.traders'],
        'too long' => [str_repeat('a', 64)],
        'reserved' => ['admin'],
        'reserved, in capitals' => ['WWW'],
        'taken' => ['acme'],
        'taken, in capitals' => ['Acme'],
        // The address of a deleted workspace is not handed out again.
        'taken by a deleted workspace' => ['gone'],
    ]);

    it('validates the rest of the form', function (array $input, string $field) {
        $workspaces = Tenant::query()->count();

        ($this->createWorkspace)($input)->assertSessionHasErrors($field);

        expect(Tenant::query()->count())->toBe($workspaces)
            ->and(($this->emails)())->toHaveCount(0);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'a name that is too long' => [['name' => str_repeat('a', 256)], 'name'],
        'no address' => [['slug' => ''], 'slug'],
        'no owner name' => [['owner_name' => ''], 'owner_name'],
        'no owner email' => [['owner_email' => ''], 'owner_email'],
        'something that is not an email' => [['owner_email' => 'nadia-at-nova'], 'owner_email'],
        'no language' => [['locale' => ''], 'locale'],
        'a language the platform does not have' => [['locale' => 'fr'], 'locale'],
        'a malformed plan id' => [['plan_id' => 'starter'], 'plan_id'],
        'an unknown interval' => [['interval' => 'weekly'], 'interval'],
    ]);

    it('does not start a workspace on a plan that is archived or does not exist', function (string $case) {
        $planId = $case === 'archived' ? $this->createPlan(['is_active' => false])->id : '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $workspaces = Tenant::query()->count();

        ($this->createWorkspace)(['plan_id' => $planId, 'interval' => 'monthly'])->assertNotFound();

        expect(Tenant::query()->count())->toBe($workspaces)
            ->and(User::query()->where('email', 'nadia@nova.test')->exists())->toBeFalse();
    })->with(['archived', 'unknown']);
});

describe('the workspace page', function () {
    it('shows the workspace at a glance, with its plan, invoices, activity and support sessions', function () {
        $starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000]);
        $hidden = $this->createPlan(['key' => 'custom', 'name' => 'Custom', 'is_public' => false, 'sort_order' => 5]);
        $this->createPlan(['key' => 'retired', 'is_active' => false]);

        // Invoiced at once and not paid yet.
        $this->subscribe($this->acme, $starter, withTrial: false);
        $invoice = $this->openInvoiceOf($this->acme);

        // Another workspace's records must not show up here.
        $globex = $this->createTenant('globex');
        $this->subscribe($globex, $starter, withTrial: false);

        $owner = $this->ownerOf($this->acme);

        $this->actingAsPlatformUser($this->admin)
            ->get("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($owner, $invoice, $starter, $hidden): void {
                $page->component('Platform/Pages/Tenants/Show')
                    ->where('app.area', 'platform')
                    ->missing('tenant')
                    ->where('workspace.id', $this->acme->id)
                    ->where('workspace.name', 'Acme Trading')
                    ->where('workspace.slug', 'acme')
                    ->where('workspace.url', 'http://acme.localhost/')
                    ->where('workspace.status', 'active')
                    ->where('workspace.locale', 'en')
                    ->where('workspace.timezone', 'Asia/Dhaka')
                    ->where('workspace.currency', 'BDT')
                    ->where('workspace.members', 3)
                    ->where('workspace.owner', ['name' => $owner->name, 'email' => $owner->email])
                    ->where('workspace.onboarded', true)
                    ->where('workspace.suspendedAt', null)
                    ->where('subscription.status', 'past_due')
                    ->where('subscription.plan', ['id' => $starter->id, 'key' => 'starter', 'name' => 'Starter'])
                    ->where('subscription.interval', 'monthly')
                    ->where('subscription.amount', 150000)
                    ->where('subscription.currency', 'BDT')
                    ->where('subscription.isFree', false)
                    ->where('subscription.locked', false)
                    ->where('subscription.pending', null)
                    ->has('invoices', 1)
                    ->where('invoices.0.id', $invoice->id)
                    ->where('invoices.0.number', $invoice->number)
                    ->where('invoices.0.status', 'open')
                    ->where('invoices.0.totalAmount', 150000)
                    // What "Set plan" can give: every active plan, public or not.
                    ->where('plans', fn ($plans): bool => collect($plans)->pluck('id')->all() === [$starter->id, $hidden->id])
                    ->where('supportSessions', [])
                    ->where('can', ['manage' => true, 'impersonate' => true, 'manageBilling' => true]);

                $events = collect($page->toArray()['props']['activity'])->pluck('event')->all();

                expect($events)->toContain('billing.subscription_started', 'billing.invoice_issued')
                    ->and(count($events))->toBeLessThanOrEqual(10);
            });

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('shows a workspace without a plan as exactly that', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subscription', null)
                ->where('invoices', [])
                ->where('plans', []));
    });

    it('lets a support admin look, and says they can change nothing', function () {
        $this->actingAsPlatformUser($this->support)
            ->get("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspace.slug', 'acme')
                ->where('can', ['manage' => false, 'impersonate' => false, 'manageBilling' => false]));
    });

    it('answers 404 for a workspace that does not exist or was deleted', function () {
        $gone = $this->createTenant('gone');
        $gone->delete();

        $this->actingAsPlatformUser($this->admin);

        $this->get('http://admin.localhost/tenants/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $this->get("http://admin.localhost/tenants/{$gone->id}")->assertNotFound();
        $this->post("http://admin.localhost/tenants/{$gone->id}/suspend", ['reason' => 'No longer a customer'])->assertNotFound();
    });
});

describe('approving', function () {
    beforeEach(function () {
        $this->waiting = $this->createTenant('waiting', ['name' => 'Waiting Co', 'status' => TenantStatus::Pending]);
    });

    it('lets a pending workspace in, tells its owner, and records it in both trails', function () {
        // Closed to everybody while it waits.
        $this->get('http://waiting.localhost/login')->assertForbidden();

        ($this->act)($this->waiting, 'approve')
            ->assertRedirect("http://admin.localhost/tenants/{$this->waiting->id}")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($this->waiting->refresh()->status)->toBe(TenantStatus::Active);

        $platform = $this->auditTrail(null, 'platform.workspace.approved')->sole();
        $workspace = $this->auditTrail($this->waiting, 'workspace.approved')->sole();

        expect($platform->actor_id)->toBe($this->admin->id)
            ->and($platform->subject_id)->toBe($this->waiting->id)
            ->and($platform->subject_label)->toBe('Waiting Co')
            ->and($workspace->actor_type)->toBe('platform_user')
            ->and($workspace->actor_id)->toBe($this->admin->id);

        // The owner already has a password: they are sent to the sign-in page.
        $message = ($this->emails)()->sole();

        expect($message->getTo()[0]->getAddress())->toBe($this->ownerOf($this->waiting)->email)
            ->and($message->getSubject())->toBe('Waiting Co is ready')
            ->and($message->getHtmlBody())->toContain('http://waiting.localhost/login')->not->toContain('reset-password');

        $this->startNewBrowserSession();
        $this->get('http://waiting.localhost/login')->assertOk();
    });

    it('starts the trial again from the day of approval, not the day of sign-up', function () {
        $this->subscribe($this->waiting, $this->createPlan(['trial_days' => 14]));

        expect($this->subscriptionOf($this->waiting)->trial_ends_at->toDateTimeString())->toBe('2026-03-24 09:00:00');

        // Five days pass before anyone looks at the sign-up.
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));

        ($this->act)($this->waiting, 'approve')->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($this->waiting);

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-03-29 12:00:00')
            ->and($this->auditTrail($this->waiting, 'billing.trial_extended')->sole()->actor_id)->toBe($this->admin->id);
    });

    it('leaves a subscription that is not a trial as it is', function (string $case) {
        match ($case) {
            'no plan' => null,
            'a free plan' => $this->subscribe($this->waiting, $this->createFreePlan()),
            'a plan without a trial' => $this->subscribe($this->waiting, $this->createPlan()),
        };

        $before = Subscription::forTenant($this->waiting)->first()?->getAttributes();

        ($this->act)($this->waiting, 'approve')->assertSessionHasNoErrors();

        expect($this->waiting->refresh()->status)->toBe(TenantStatus::Active)
            ->and(Subscription::forTenant($this->waiting)->first()?->getAttributes())->toEqual($before)
            ->and($this->auditTrail($this->waiting, 'billing.trial_extended'))->toHaveCount(0);
    })->with(['no plan', 'a free plan', 'a plan without a trial']);

    it('refuses a workspace that is not waiting for approval', function (TenantStatus $status) {
        $this->acme->update(['status' => $status]);

        ($this->act)($this->acme, 'approve')
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasErrors(['workspace' => 'This workspace is not waiting for approval.']);

        expect($this->acme->refresh()->status)->toBe($status)
            ->and($this->auditTrail(null, 'platform.workspace.approved'))->toHaveCount(0)
            ->and(($this->emails)())->toHaveCount(0);
    })->with([TenantStatus::Active, TenantStatus::Suspended]);
});

describe('suspending', function () {
    it('needs a reason of at least five characters', function (array $input) {
        ($this->act)($this->acme, 'suspend', $input)
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasErrors('reason');

        expect($this->acme->refresh()->status)->toBe(TenantStatus::Active)
            ->and($this->auditTrail(null, 'platform.workspace.suspended'))->toHaveCount(0);
    })->with([
        'none' => [[]],
        'empty' => [['reason' => '']],
        'four characters' => [['reason' => 'spam']],
        'too long' => [['reason' => str_repeat('a', 501)]],
        'not text' => [['reason' => ['unpaid']]],
    ]);

    it('blocks every request to the workspace and keeps its data', function () {
        $members = $this->acme->memberships()->count();

        ($this->act)($this->acme, 'suspend', ['reason' => 'Unpaid for three months'])
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $this->acme->refresh();

        expect($this->acme->status)->toBe(TenantStatus::Suspended)
            ->and($this->acme->suspended_at->toDateTimeString())->toBe('2026-03-10 09:00:00')
            ->and($this->acme->memberships()->count())->toBe($members);

        // Signed out or signed in, the workspace's host answers "unavailable".
        $this->startNewBrowserSession();

        $this->get('http://acme.localhost/login')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Errors/WorkspaceUnavailable')
                ->where('workspace', 'Acme Trading')
                ->where('status', 'suspended'));

        $this->actingAsOwner($this->acme)->get('http://acme.localhost/')->assertForbidden();
        $this->actingAsOwner($this->acme)->get('http://acme.localhost/team/members')->assertForbidden();

        // The panel still shows it, with when it was suspended.
        $this->startNewBrowserSession()
            ->actingAsPlatformUser($this->admin)
            ->get("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspace.status', 'suspended')
                ->where('workspace.suspendedAt', '2026-03-10T09:00:00+00:00'));
    });

    it('records who suspended it and why, for the platform and for the workspace', function () {
        ($this->act)($this->acme, 'suspend', ['reason' => 'Unpaid for three months']);

        $platform = $this->auditTrail(null, 'platform.workspace.suspended')->sole();
        $workspace = $this->auditTrail($this->acme, 'workspace.suspended')->sole();

        foreach ([$platform, $workspace] as $entry) {
            expect($entry->actor_type)->toBe('platform_user')
                ->and($entry->actor_id)->toBe($this->admin->id)
                ->and($entry->actor_label)->toBe($this->admin->email)
                ->and($entry->subject_type)->toBe('tenant')
                ->and($entry->subject_id)->toBe($this->acme->id)
                ->and($entry->subject_label)->toBe('Acme Trading')
                ->and($entry->properties)->toEqual(['reason' => 'Unpaid for three months']);
        }

        // One fact, two trails: the platform's own, and the one the workspace's owner reads.
        expect($platform->tenant_id)->toBeNull()
            ->and($workspace->tenant_id)->toBe($this->acme->id);
    });

    it('only suspends a workspace that is active', function (TenantStatus $status) {
        $this->acme->update(['status' => $status]);

        ($this->act)($this->acme, 'suspend', ['reason' => 'Unpaid for three months'])
            ->assertSessionHasErrors(['workspace' => 'Only an active workspace can be suspended.']);

        expect($this->acme->refresh()->status)->toBe($status)
            ->and($this->auditTrail($this->acme, 'workspace.suspended'))->toHaveCount(0);
    })->with([TenantStatus::Pending, TenantStatus::Suspended]);
});

describe('reactivating', function () {
    beforeEach(function () {
        ($this->act)($this->acme, 'suspend', ['reason' => 'Unpaid for three months']);
        $this->startNewBrowserSession();
    });

    it('opens the workspace again and records it in both trails', function () {
        $this->travel(2)->days();

        ($this->act)($this->acme, 'reactivate', ['reason' => 'Paid in full by bank transfer'])
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $this->acme->refresh();

        expect($this->acme->status)->toBe(TenantStatus::Active)
            ->and($this->acme->suspended_at)->toBeNull();

        $platform = $this->auditTrail(null, 'platform.workspace.reactivated')->sole();
        $workspace = $this->auditTrail($this->acme, 'workspace.reactivated')->sole();

        expect($platform->actor_id)->toBe($this->admin->id)
            ->and($platform->properties)->toEqual(['reason' => 'Paid in full by bank transfer'])
            ->and($workspace->actor_id)->toBe($this->admin->id)
            ->and($workspace->properties)->toEqual(['reason' => 'Paid in full by bank transfer']);

        $this->startNewBrowserSession();
        $this->get('http://acme.localhost/login')->assertOk();
        $this->actingAsOwner($this->acme)->get('http://acme.localhost/')->assertOk();
    });

    it('does not insist on a reason', function () {
        ($this->act)($this->acme, 'reactivate')->assertSessionHasNoErrors();

        expect($this->acme->refresh()->status)->toBe(TenantStatus::Active)
            ->and($this->auditTrail($this->acme, 'workspace.reactivated')->sole()->properties)->toBeNull();
    });

    it('refuses a reason that is not text or is too long', function (mixed $reason) {
        ($this->act)($this->acme, 'reactivate', ['reason' => $reason])->assertSessionHasErrors('reason');

        expect($this->acme->refresh()->status)->toBe(TenantStatus::Suspended);
    })->with([
        'too long' => [str_repeat('a', 501)],
        'a list' => [['paid']],
    ]);

    it('refuses a workspace that is not suspended', function () {
        ($this->act)($this->acme, 'reactivate');

        ($this->act)($this->acme, 'reactivate')
            ->assertSessionHasErrors(['workspace' => 'This workspace is not suspended.']);

        expect($this->auditTrail($this->acme, 'workspace.reactivated'))->toHaveCount(1);
    });
});

describe('who may do it', function () {
    it('lets a support admin read but not create, approve, suspend or reactivate', function () {
        $waiting = $this->createTenant('waiting', ['status' => TenantStatus::Pending]);
        $parked = $this->createTenant('parked', ['status' => TenantStatus::Suspended]);
        $workspaces = Tenant::query()->count();

        $this->actingAsPlatformUser($this->support);

        // Reading.
        $this->get('http://admin.localhost/tenants')->assertOk();
        $this->get("http://admin.localhost/tenants/{$this->acme->id}")->assertOk();

        // Changing.
        $this->get('http://admin.localhost/tenants/create')->assertForbidden();
        ($this->createWorkspace)(as: $this->support)->assertForbidden();
        ($this->act)($waiting, 'approve', as: $this->support)->assertForbidden();
        ($this->act)($this->acme, 'suspend', ['reason' => 'Unpaid for three months'], $this->support)->assertForbidden();
        ($this->act)($parked, 'reactivate', as: $this->support)->assertForbidden();

        expect(Tenant::query()->count())->toBe($workspaces)
            ->and($waiting->refresh()->status)->toBe(TenantStatus::Pending)
            ->and($this->acme->refresh()->status)->toBe(TenantStatus::Active)
            ->and($parked->refresh()->status)->toBe(TenantStatus::Suspended)
            ->and($this->auditTrail()->pluck('event')->filter(fn (string $event): bool => str_starts_with($event, 'platform.workspace.'))->all())->toBe([])
            ->and(($this->emails)())->toHaveCount(0);
    });

    it('sends a guest to the panel\'s sign-in page', function (string $method, string $path) {
        $path = str_replace('{id}', $this->acme->id, $path);

        $this->call($method, 'http://admin.localhost'.$path, ['reason' => 'Unpaid for three months'])
            ->assertRedirect('http://admin.localhost/login');

        expect($this->acme->refresh()->status)->toBe(TenantStatus::Active);
    })->with([
        ['GET', '/tenants'],
        ['GET', '/tenants/create'],
        ['POST', '/tenants'],
        ['GET', '/tenants/{id}'],
        ['POST', '/tenants/{id}/approve'],
        ['POST', '/tenants/{id}/suspend'],
        ['POST', '/tenants/{id}/reactivate'],
    ]);

    it('does not accept a workspace session, whoever it belongs to', function () {
        $this->actingAsOwner($this->acme)
            ->post("http://admin.localhost/tenants/{$this->acme->id}/suspend", ['reason' => 'Unpaid for three months'])
            ->assertRedirect('http://admin.localhost/login');

        expect($this->acme->refresh()->status)->toBe(TenantStatus::Active);
    });

    it('keeps an account without two-factor out of all of it', function (string $method, string $path) {
        $unenrolled = $this->createPlatformUser(twoFactor: false);
        $path = str_replace('{id}', $this->acme->id, $path);

        $this->actingAsPlatformUser($unenrolled)
            ->call($method, 'http://admin.localhost'.$path, ['reason' => 'Unpaid for three months'])
            ->assertRedirect('http://admin.localhost/two-factor/setup');

        expect($this->acme->refresh()->status)->toBe(TenantStatus::Active);
    })->with([
        ['GET', '/tenants/create'],
        ['POST', '/tenants'],
        ['GET', '/tenants/{id}'],
        ['POST', '/tenants/{id}/approve'],
        ['POST', '/tenants/{id}/suspend'],
        ['POST', '/tenants/{id}/reactivate'],
    ]);
});
