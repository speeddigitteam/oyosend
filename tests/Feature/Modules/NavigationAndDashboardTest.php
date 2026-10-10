<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Audit\AuditLogger;
use App\Core\Dashboard\DashboardBuilder;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\NavigationBuilder;
use App\Core\Modules\NavigationItem;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->memberWith = fn (string $role): User => $role === Role::OWNER
        ? $this->ownerOf($this->acme)
        : $this->addMember($this->acme, $role);

    /** Sidebar as [section => [item keys]]. */
    $this->sidebar = function (User $user): array {
        $sections = [];

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $user)
            ->get('http://acme.localhost/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$sections): void {
                foreach ($page->toArray()['props']['navigation'] as $section) {
                    $sections[$section['key']] = array_column($section['items'], 'key');
                }
            });

        return $sections;
    };

    $this->widgets = function (User $user): array {
        $widgets = [];

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $user)
            ->get('http://acme.localhost/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$widgets): void {
                $page->component('Core/Pages/Dashboard');

                $widgets = collect($page->toArray()['props']['widgets'])->keyBy('key')->all();
            });

        return $widgets;
    };
});

describe('the sidebar', function () {
    it('only lists what the person may open', function (string $role, array $expected) {
        expect(($this->sidebar)(($this->memberWith)($role)))->toBe($expected);
    })->with([
        'owner' => [Role::OWNER, [
            'workspace' => ['dashboard', 'contacts'],
            'apps' => ['appStore'],
            'manage' => ['members', 'roles', 'organization', 'audit', 'workspaceSettings', 'billing'],
        ]],
        // Billing stays with the owner.
        'admin' => [Role::ADMIN, [
            'workspace' => ['dashboard', 'contacts'],
            'apps' => ['appStore'],
            'manage' => ['members', 'roles', 'organization', 'audit', 'workspaceSettings'],
        ]],
        'manager' => [Role::MANAGER, ['workspace' => ['dashboard', 'contacts'], 'apps' => ['appStore'], 'manage' => ['members', 'roles', 'organization']]],
        'employee' => [Role::EMPLOYEE, ['workspace' => ['dashboard', 'contacts'], 'apps' => ['appStore'], 'manage' => ['members', 'organization']]],
    ]);

    it('leaves out a whole section when nothing in it is allowed', function () {
        $guest = $this->addMember($this->acme, $this->createRole($this->acme, 'Guests'));

        expect(($this->sidebar)($guest))->toBe(['workspace' => ['dashboard']]);
    });

    it('describes each entry for the client, linking into this workspace', function () {
        $this->actingAsOwner($this->acme)
            ->get('http://acme.localhost/')
            ->assertInertia(function (AssertableInertia $page): void {
                $manage = collect($page->toArray()['props']['navigation'])->firstWhere('key', 'manage');

                expect($manage['items'][0])->toBe([
                    'key' => 'members',
                    'route' => 'core.members.index',
                    'href' => 'http://acme.localhost/team/members',
                    'icon' => 'users',
                    'active' => 'core.members.*',
                ]);
            });
    });

    it('is empty on pages for signed-out visitors', function () {
        $this->get('http://acme.localhost/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('navigation', []));
    });

    it('skips an entry whose route does not exist and orders the rest', function () {
        app(ModuleRegistry::class)->register(new ModuleManifest(
            key: 'extras',
            name: 'Extras',
            version: '1.0.0',
            core: true,
            navigation: [
                new NavigationItem('ghost', 'extras.route.that.does.not.exist', 'ghost', section: 'workspace', order: 1),
                new NavigationItem('profile', 'core.settings.profile', 'user', section: 'workspace', order: 5),
            ],
        ));
        app(PlanEntitlementChecker::class)->forget();

        $sections = $this->inTenant($this->acme, fn (): array => app(NavigationBuilder::class)->for($this->ownerOf($this->acme)));

        expect(array_column($sections[0]['items'], 'key'))->toBe(['profile', 'dashboard', 'contacts']);
    });

    it('cannot be built without a tenant', function () {
        expect(fn () => app(NavigationBuilder::class)->for($this->ownerOf($this->acme)))
            ->toThrow(MissingTenantContextException::class);
    });
});

describe('the dashboard', function () {
    it('only shows the widgets the person may see', function (string $role, array $expected) {
        expect(array_keys(($this->widgets)(($this->memberWith)($role))))->toBe($expected);
    })->with([
        'owner' => [Role::OWNER, ['core.team', 'core.security', 'core.activity']],
        'admin' => [Role::ADMIN, ['core.team', 'core.security', 'core.activity']],
        'manager' => [Role::MANAGER, ['core.team', 'core.security']],
        'employee' => [Role::EMPLOYEE, ['core.team', 'core.security']],
    ]);

    it('shows someone with no permissions only their own security status', function () {
        $guest = $this->addMember($this->acme, $this->createRole($this->acme, 'Guests'));

        expect(array_keys(($this->widgets)($guest)))->toBe(['core.security']);
    });

    it('counts the team of this workspace only', function () {
        // acme: owner + 3 active + 1 suspended, 2 open invitations (one expired), 5 roles.
        foreach (range(1, 3) as $ignored) {
            $this->addMember($this->acme, Role::EMPLOYEE);
        }

        $this->addMember($this->acme, Role::EMPLOYEE, status: MembershipStatus::Suspended);
        $this->createInvitation($this->acme, 'pending@example.test');
        $this->createInvitation($this->acme, 'expired@example.test', attributes: ['expires_at' => now()->subDay()]);
        $this->createInvitation($this->acme, 'revoked@example.test', attributes: ['revoked_at' => now()]);
        $this->createRole($this->acme, 'Custom');

        // Noise next door.
        foreach (range(1, 4) as $ignored) {
            $this->addMember($this->globex, Role::EMPLOYEE);
        }

        $this->createInvitation($this->globex, 'elsewhere@example.test');

        $team = ($this->widgets)($this->ownerOf($this->acme))['core.team'];

        expect($team['component'])->toBe('TeamWidget')
            ->and($team['span'])->toBe(7)
            ->and($team['data'])->toMatchArray([
                'members' => 4,
                'suspended' => 1,
                'pendingInvitations' => 1,
                'roles' => 5,
                'joinedThisMonth' => 5,
            ])
            ->and($team['data']['recent'])->toHaveCount(4)
            ->and(array_keys($team['data']['recent'][0]))->toBe(['id', 'name', 'initials', 'joinedAt']);
    });

    it('describes the viewer\'s own account security', function () {
        $member = $this->addMember($this->acme, Role::EMPLOYEE);

        expect(($this->widgets)($member)['core.security']['data'])->toBe([
            'twoFactorEnabled' => false,
            'recoveryCodesRemaining' => null,
            'lastLoginAt' => null,
            'emailVerified' => true,
        ]);

        $this->enableTwoFactor($member);

        expect(($this->widgets)($member)['core.security']['data'])->toMatchArray([
            'twoFactorEnabled' => true,
            'recoveryCodesRemaining' => 8,
        ]);
    });

    it('shows the latest activity of this workspace only', function () {
        $this->inTenant($this->globex, fn () => app(AuditLogger::class)->record('globex.private_event'));
        app(AuditLogger::class)->record('platform.private_event');

        $this->inTenant($this->acme, function (): void {
            foreach (range(1, 10) as $n) {
                app(AuditLogger::class)->record("acme.event_{$n}");
            }
        });

        $activity = ($this->widgets)($this->ownerOf($this->acme))['core.activity']['data']['entries'];

        expect(array_column($activity, 'event'))->toBe([
            'acme.event_10', 'acme.event_9', 'acme.event_8', 'acme.event_7',
            'acme.event_6', 'acme.event_5', 'acme.event_4', 'acme.event_3',
        ]);
    });

    it('cannot be built without a tenant', function () {
        expect(fn () => app(DashboardBuilder::class)->for($this->ownerOf($this->acme)))
            ->toThrow(MissingTenantContextException::class);
    });
});
