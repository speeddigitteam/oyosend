<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\SubscriptionManager;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Platform\Impersonation\ImpersonationManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/*
 * The paywall (`subscribed`): a workspace whose subscription has ended keeps
 * its data but is locked down to signing in, personal settings and billing.
 * And what every workspace page is told about the subscription and about a
 * support session, for the notices in the layout.
 *
 * Defaults (config/platform.php): 7 days of grace after a payment was due.
 */
beforeEach(function () {
    Mail::fake();

    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    $this->acme = $this->createTenant('acme', ['name' => 'Acme']);
    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'monthly_amount' => 150000]);
    $this->pro = $this->createPlan(['key' => 'pro', 'name' => 'Pro', 'monthly_amount' => 200000, 'trial_days' => 14]);

    $this->owner = $this->ownerOf($this->acme);

    // Both sides of `core.billing.view`. Neither system role below Owner holds it.
    $this->createRole($this->acme, 'Accountant', ['core.billing.view', 'core.members.view']);
    $this->accountant = $this->addMember($this->acme, 'Accountant');
    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);

    /**
     * End the workspace's subscription the way production does: by letting
     * time pass and the billing cycle run.
     */
    $this->endSubscription = function (string $as): void {
        if ($as === 'canceled') {
            // Paid for a month, canceled, and the month is over.
            $this->subscribeAndPay($this->acme, $this->starter);
            $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());
            $this->travelTo(Carbon::parse('2026-04-10 10:00:00'));
        } else {
            // The first invoice was never paid and the grace period ran out.
            $this->subscribe($this->acme, $this->starter, withTrial: false);
            $this->travel(8)->days();
        }

        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status->value)->toBe($as);
    };

    $this->invitations = fn (): int => $this->inTenant($this->acme, fn (): int => Invitation::query()->count());

    $this->iso = fn (string $time): string => Carbon::parse($time)->toIso8601String();
});

describe('a workspace whose subscription has ended', function () {
    it('sends the owner to billing from wherever they were going', function (string $as) {
        ($this->endSubscription)($as);

        $this->actingAsMember($this->acme, $this->owner);

        foreach (['', 'team/members', 'team/roles', 'audit-log', 'settings/workspace', 'organization', 'contacts'] as $path) {
            $this->get($this->tenantUrl($this->acme, $path))
                ->assertRedirect('http://acme.localhost/billing')
                ->assertInertiaFlash('toast.type', 'warning');
        }
    })->with(['canceled', 'expired']);

    it('sends anyone who may see billing there, not only the owner', function () {
        ($this->endSubscription)('expired');

        $this->actingAsMember($this->acme, $this->accountant);

        $this->get('http://acme.localhost')->assertRedirect('http://acme.localhost/billing');
        $this->get('http://acme.localhost/team/members')->assertRedirect('http://acme.localhost/billing');

        // Where they arrive is open to them.
        $this->get('http://acme.localhost/billing')->assertOk();
    });

    it('shows a member who cannot see billing that the workspace is locked', function (string $as) {
        ($this->endSubscription)($as);

        $this->actingAsMember($this->acme, $this->employee)
            ->get('http://acme.localhost')
            ->assertStatus(402)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Errors/WorkspaceLocked')
                ->where('workspace', 'Acme')
                ->where('status', $as)
                // The page says who is signed in and offers their own settings.
                ->where('auth.user.email', $this->employee->email)
                ->where('subscription.locked', true));

        $this->get('http://acme.localhost/team/members')
            ->assertStatus(402)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('status', $as));
    })->with(['canceled', 'expired']);

    it('locks out a role that holds everything but billing', function () {
        ($this->endSubscription)('expired');

        $this->actingAsMember($this->acme, $this->admin);

        foreach (['', 'team/members', 'team/roles', 'audit-log', 'settings/workspace'] as $path) {
            $this->get($this->tenantUrl($this->acme, $path))->assertStatus(402);
        }
    });

    it('answers a visit made from inside the app with the same locked page', function () {
        ($this->endSubscription)('expired');

        $this->actingAsMember($this->acme, $this->employee);

        // A page that is still open, loaded first ...
        $version = $this->get('http://acme.localhost/settings/profile')->assertOk()->inertiaPage()['version'];

        // ... and what the browser sends when a link on it is followed.
        $this->get('http://acme.localhost/team/members', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertStatus(402)
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Core/Pages/Errors/WorkspaceLocked')
            ->assertJsonPath('props.workspace', 'Acme')
            ->assertJsonPath('props.status', 'expired');
    });

    it('refuses to change anything, too', function () {
        ($this->endSubscription)('expired');

        $invitation = ['email' => 'nadia@example.test', 'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id];
        $membership = $this->membershipOf($this->acme, $this->employee);

        // The admin may invite and manage members - in a workspace that is open.
        $this->actingAsMember($this->acme, $this->admin);

        $this->post('http://acme.localhost/team/invitations', $invitation)->assertStatus(402);
        $this->patch("http://acme.localhost/team/members/{$membership->id}", ['status' => 'suspended'])->assertStatus(402);
        $this->delete("http://acme.localhost/team/members/{$membership->id}")->assertStatus(402);
        $this->put('http://acme.localhost/settings/workspace', ['name' => 'Renamed', 'locale' => 'en', 'timezone' => 'Asia/Dhaka', 'currency' => 'BDT'])
            ->assertStatus(402);

        // The owner is sent to billing instead, and nothing happens either.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->owner);

        $this->post('http://acme.localhost/team/invitations', $invitation)->assertRedirect('http://acme.localhost/billing');
        $this->delete("http://acme.localhost/team/members/{$membership->id}")->assertRedirect('http://acme.localhost/billing');
        $this->put('http://acme.localhost/settings/workspace', ['name' => 'Renamed', 'locale' => 'en', 'timezone' => 'Asia/Dhaka', 'currency' => 'BDT'])
            ->assertRedirect('http://acme.localhost/billing');

        expect(($this->invitations)())->toBe(0)
            ->and($this->acme->refresh()->name)->toBe('Acme')
            ->and(TenantMembership::forTenant($this->acme)->whereKey($membership->id)->value('status')->value)->toBe('active');
    });

    it('turns a member away as locked before it says what they may not do', function () {
        ($this->endSubscription)('expired');

        // The employee may not invite anyone; in a locked workspace that is beside the point.
        $this->actingAsMember($this->acme, $this->employee)
            ->post('http://acme.localhost/team/invitations', ['email' => 'nadia@example.test', 'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id])
            ->assertStatus(402);
    });

    it('still lets people sign in and sign out', function () {
        ($this->endSubscription)('expired');

        $this->get('http://acme.localhost/login')->assertOk();
        $this->get('http://acme.localhost/forgot-password')->assertOk();

        $this->post('http://acme.localhost/login', ['email' => $this->employee->email, 'password' => 'password'])
            ->assertRedirect('http://acme.localhost');

        // Signed in - to a locked workspace.
        $this->get('http://acme.localhost')->assertStatus(402);

        $this->post('http://acme.localhost/logout')->assertRedirect('http://acme.localhost/login');

        // A visitor is asked to sign in; the locked page is for members.
        $this->get('http://acme.localhost')->assertRedirect('http://acme.localhost/login');
    });

    it('keeps personal settings open to everyone', function () {
        ($this->endSubscription)('expired');

        foreach ([$this->employee, $this->owner] as $member) {
            $this->startNewBrowserSession()->actingAsMember($this->acme, $member);

            $this->get('http://acme.localhost/settings/profile')->assertOk();
            $this->get('http://acme.localhost/settings/security')->assertOk();
        }

        // And they still work.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->employee);

        $this->put('http://acme.localhost/settings/profile', ['name' => 'Nadia Islam', 'locale' => 'bn', 'numerals' => 'beng'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->put('http://acme.localhost/settings/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect($this->employee->refresh()->name)->toBe('Nadia Islam');
    });

    it('keeps billing open to those who may see it, and to nobody else', function () {
        ($this->endSubscription)('expired');

        $this->actingAsMember($this->acme, $this->owner)->get('http://acme.localhost/billing')->assertOk();
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->accountant)->get('http://acme.localhost/billing')->assertOk();

        // Outside the paywall the ordinary permission check answers: forbidden, not locked.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->employee)->get('http://acme.localhost/billing')->assertForbidden();
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->admin)->get('http://acme.localhost/billing')->assertForbidden();
    });

    it('opens again as soon as what is owed has been paid', function () {
        ($this->endSubscription)('expired');

        $this->actingAsMember($this->acme, $this->employee);

        $this->get('http://acme.localhost')->assertStatus(402);

        $this->payOpenInvoice($this->acme);

        $this->get('http://acme.localhost')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Dashboard')
                ->where('subscription.status', 'active')
                ->where('subscription.locked', false));
        $this->get('http://acme.localhost/team/members')->assertOk();
    });

    it('locks that workspace only', function () {
        $globex = $this->createTenant('globex');
        // Someone who belongs to both.
        $this->addMember($globex, Role::EMPLOYEE, $this->employee);

        ($this->endSubscription)('expired');

        $this->actingAsMember($this->acme, $this->employee)->get('http://acme.localhost')->assertStatus(402);

        $this->startNewBrowserSession()->actingAsMember($globex, $this->employee)
            ->get('http://globex.localhost')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription', null));
        $this->startNewBrowserSession()->actingAsOwner($globex)->get('http://globex.localhost/team/members')->assertOk();
    });
});

describe('a workspace that is not locked', function () {
    beforeEach(function () {
        /** The workspace works for the person who pays and for the person who does not. */
        $this->assertOpen = function (): void {
            foreach ([$this->owner, $this->employee] as $member) {
                $this->startNewBrowserSession()->actingAsMember($this->acme, $member);

                $this->get('http://acme.localhost')->assertOk();
                $this->get('http://acme.localhost/team/members')->assertOk();
            }
        };
    });

    it('is open without any subscription: there is nothing it owes', function () {
        ($this->assertOpen)();

        // Changes go through as well.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/invitations', ['email' => 'nadia@example.test', 'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(($this->invitations)())->toBe(1);
    });

    it('is open on a free plan, for as long as it likes', function () {
        $this->subscribe($this->acme, $this->createFreePlan());

        $this->travel(400)->days();
        $this->runBillingCycle($this->acme);

        ($this->assertOpen)();
    });

    it('is open during a trial', function () {
        $this->subscribe($this->acme, $this->pro);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Trialing);

        ($this->assertOpen)();
    });

    it('is open in a paid period, also once it has been set to end', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        ($this->assertOpen)();

        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());

        // The last hour of what was paid for.
        $this->travelTo(Carbon::parse('2026-04-10 08:00:00'));
        $this->runBillingCycle($this->acme);

        ($this->assertOpen)();
    });

    it('stays open while a payment is overdue, until the grace period ends', function () {
        $this->subscribe($this->acme, $this->pro);

        // The trial ended unpaid a day ago.
        $this->travelTo(Carbon::parse('2026-03-25 09:00:00'));
        $this->runBillingCycle($this->acme);

        expect($this->subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);

        ($this->assertOpen)();

        // The last hour of grace ...
        $this->travelTo(Carbon::parse('2026-03-31 08:00:00'));
        $this->runBillingCycle($this->acme);

        ($this->assertOpen)();

        // ... and the hour after it.
        $this->travelTo(Carbon::parse('2026-03-31 10:00:00'));
        $this->runBillingCycle($this->acme);

        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->employee)->get('http://acme.localhost')->assertStatus(402);
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->owner)->get('http://acme.localhost')->assertRedirect('http://acme.localhost/billing');
    });
});

describe('what every page is told about the subscription', function () {
    beforeEach(function () {
        /** The shared `subscription` prop, as this person gets it on a page that is always reachable. */
        $this->notice = fn ($user = null): ?array => $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $user ?? $this->employee)
            ->get('http://acme.localhost/settings/profile')
            ->assertOk()
            ->inertiaProps('subscription');
    });

    it('is told nothing when there is no subscription', function () {
        expect(($this->notice)())->toBeNull()
            ->and(($this->notice)($this->owner))->toBeNull();
    });

    it('is told about a trial and when it ends', function () {
        $this->subscribe($this->acme, $this->pro);

        // toEqual: the order of the keys is not part of the contract.
        expect(($this->notice)())->toEqual([
            'status' => 'trialing',
            'plan' => 'Pro',
            'locked' => false,
            'trialEndsAt' => ($this->iso)('2026-03-24 09:00:00'),
            'graceEndsAt' => null,
            'cancelAtPeriodEnd' => false,
            'periodEnd' => null,
        ]);
    });

    it('is told about a paid period and whether it is set to end', function () {
        $this->subscribeAndPay($this->acme, $this->starter);

        expect(($this->notice)())->toEqual([
            'status' => 'active',
            'plan' => 'Starter',
            'locked' => false,
            'trialEndsAt' => null,
            'graceEndsAt' => null,
            'cancelAtPeriodEnd' => false,
            'periodEnd' => ($this->iso)('2026-04-10 09:00:00'),
        ]);

        $this->inTenant($this->acme, fn () => app(SubscriptionManager::class)->cancel());

        expect(($this->notice)())->toMatchArray([
            'status' => 'active',
            'locked' => false,
            'cancelAtPeriodEnd' => true,
            'periodEnd' => ($this->iso)('2026-04-10 09:00:00'),
        ]);
    });

    it('is told about an overdue payment and when the workspace will be locked', function () {
        $this->subscribe($this->acme, $this->starter, withTrial: false);

        expect(($this->notice)())->toEqual([
            'status' => 'past_due',
            'plan' => 'Starter',
            'locked' => false,
            'trialEndsAt' => null,
            'graceEndsAt' => ($this->iso)('2026-03-17 09:00:00'),
            'cancelAtPeriodEnd' => false,
            'periodEnd' => ($this->iso)('2026-03-10 09:00:00'),
        ]);
    });

    it('is told that the workspace is locked', function (string $as) {
        ($this->endSubscription)($as);

        foreach ([$this->employee, $this->owner] as $member) {
            expect(($this->notice)($member))->toMatchArray([
                'status' => $as,
                'plan' => 'Starter',
                'locked' => true,
                'graceEndsAt' => null,
                'cancelAtPeriodEnd' => false,
            ]);
        }
    })->with(['canceled', 'expired']);

    it('tells everyone in the workspace the same, whatever they may do about it', function () {
        $this->subscribe($this->acme, $this->pro);

        expect(($this->notice)($this->employee))->toEqual(($this->notice)($this->owner))
            ->and(($this->notice)($this->accountant))->toEqual(($this->notice)($this->owner));
    });

    it('tells a visitor who has not signed in nothing about the plan', function () {
        $this->subscribe($this->acme, $this->pro);

        $this->get('http://acme.localhost/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription', null)->where('supportSession', null));
    });

    it('says who may open billing, so the notice can link there or not', function () {
        $permissions = fn ($user): array => $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $user)
            ->get('http://acme.localhost/settings/profile')
            ->inertiaProps('auth.permissions');

        expect($permissions($this->owner))->toContain('core.billing.view')
            ->and($permissions($this->accountant))->toContain('core.billing.view')
            ->and($permissions($this->admin))->not->toContain('core.billing.view')
            ->and($permissions($this->employee))->not->toContain('core.billing.view');
    });
});

describe('what every page is told about a support session', function () {
    it('is told there is none in an ordinary session', function () {
        foreach ([$this->owner, $this->employee] as $member) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $member)
                ->get('http://acme.localhost')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('supportSession', null));
        }
    });

    it('is told who opened one and when it ends, on every page until it is ended', function () {
        $operator = $this->createPlatformUser();

        $arrival = $this->inCentral(fn (): string => app(ImpersonationManager::class)->start($operator, $this->acme, 'Asked for help with their invoice.'));

        $this->get($arrival)->assertRedirect('http://acme.localhost');

        $expected = ['by' => $operator->email, 'expiresAt' => ($this->iso)('2026-03-10 09:30:00')];

        foreach (['', 'team/members', 'settings/workspace'] as $path) {
            $this->get($this->tenantUrl($this->acme, $path))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('supportSession', $expected)
                    // Signed in as the owner, in this workspace and no other.
                    ->where('auth.user.email', $this->owner->email)
                    ->where('workspaces', []));
        }

        // The banner's button.
        $this->post('http://acme.localhost/auth/impersonate/stop')
            ->assertRedirect("http://admin.localhost/tenants/{$this->acme->id}");

        $this->get('http://acme.localhost')->assertRedirect('http://acme.localhost/login');

        expect($this->auditTrail($this->acme, 'support.session_started'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'support.session_ended'))->toHaveCount(1);
    });
});
