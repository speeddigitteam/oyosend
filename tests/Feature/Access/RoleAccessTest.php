<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

const CORE_PERMISSIONS = [
    'core.apps.manage',
    'core.apps.view',
    'core.audit.view',
    'core.billing.manage',
    'core.billing.view',
    'core.contacts.delete',
    'core.contacts.manage',
    'core.contacts.view',
    'core.members.invite',
    'core.members.manage',
    'core.members.view',
    'core.organization.manage',
    'core.organization.view',
    'core.roles.manage',
    'core.roles.view',
    'core.settings.manage',
];

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->as = function (string $role): User {
        return $role === Role::OWNER ? $this->ownerOf($this->acme) : $this->addMember($this->acme, $role);
    };

    /** A fresh employee to act on, and the id of a custom role to edit. */
    $this->target = fn () => $this->membershipOf($this->acme, $this->addMember($this->acme, Role::EMPLOYEE));
});

/*
 * Who may open what. `true` = allowed (any non-error status), `false` = 403.
 * Columns: Owner, Admin, Manager, Employee.
 */
dataset('workspace routes', [
    'dashboard' => ['GET', '/', [true, true, true, true]],
    'own profile' => ['GET', '/settings/profile', [true, true, true, true]],
    'own security settings' => ['GET', '/settings/security', [true, true, true, true]],
    'members list' => ['GET', '/team/members', [true, true, true, true]],
    'change a member' => ['PATCH', '/team/members/{member}', [true, true, false, false]],
    'remove a member' => ['DELETE', '/team/members/{member}', [true, true, false, false]],
    'invite' => ['POST', '/team/invitations', [true, true, true, false]],
    'revoke an invitation' => ['DELETE', '/team/invitations/{invitation}', [true, true, true, false]],
    'roles list' => ['GET', '/team/roles', [true, true, true, false]],
    'new role form' => ['GET', '/team/roles/create', [true, true, false, false]],
    'create a role' => ['POST', '/team/roles', [true, true, false, false]],
    'edit role form' => ['GET', '/team/roles/{role}/edit', [true, true, false, false]],
    'update a role' => ['PUT', '/team/roles/{role}', [true, true, false, false]],
    'delete a role' => ['DELETE', '/team/roles/{role}', [true, true, false, false]],
    'audit trail' => ['GET', '/audit-log', [true, true, false, false]],
]);

it('gives each system role exactly the access it is defined with', function (string $method, string $path, array $allowed) {
    foreach ([Role::OWNER, Role::ADMIN, Role::MANAGER, Role::EMPLOYEE] as $index => $role) {
        // Fresh records per attempt, so an allowed DELETE does not starve the next role.
        [$invitation] = $this->createInvitation($this->acme, "invitee-{$index}@example.test");

        $url = $this->tenantUrl($this->acme, strtr($path, [
            '{member}' => ($this->target)()->id,
            '{invitation}' => $invitation->id,
            '{role}' => $this->createRole($this->acme, "Custom {$index}")->id,
        ]));

        $response = $this->startNewBrowserSession()
            ->actingAsMember($this->acme, ($this->as)($role))
            ->call($method, $url, [
                'status' => 'suspended',
                'email' => "someone-{$index}@example.test",
                'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id,
                'name' => "Renamed {$index}",
                'permissions' => [],
            ]);

        if ($allowed[$index]) {
            expect($response->getStatusCode())->toBeIn([200, 302], "{$role} was refused [{$method} {$path}]");
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertForbidden();
        }
    }
})->with('workspace routes');

it('renders the error page for a refused request', function () {
    $this->actingAsMember($this->acme, ($this->as)(Role::EMPLOYEE))
        ->get('http://acme.localhost/audit-log')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Errors/Error')
            ->where('status', 403));
});

it('tells the interface which permissions the person holds', function (string $role, array $expected) {
    $this->actingAsMember($this->acme, ($this->as)($role))
        ->get('http://acme.localhost/')
        ->assertInertia(function (AssertableInertia $page) use ($role, $expected): void {
            $held = $page->toArray()['props']['auth']['permissions'];
            sort($held);

            expect($held)->toBe($expected)
                ->and($page->toArray()['props']['auth']['membership']['isOwner'])->toBe($role === Role::OWNER);
        });
})->with([
    'owner' => [Role::OWNER, CORE_PERMISSIONS],
    // Everything but billing, which stays with the owner.
    'admin' => [Role::ADMIN, array_values(array_diff(CORE_PERMISSIONS, ['core.billing.manage', 'core.billing.view']))],
    'manager' => [Role::MANAGER, [
        'core.apps.view', 'core.contacts.manage', 'core.contacts.view', 'core.members.invite', 'core.members.view', 'core.organization.view', 'core.roles.view',
    ]],
    'employee' => [Role::EMPLOYEE, ['core.apps.view', 'core.contacts.manage', 'core.contacts.view', 'core.members.view', 'core.organization.view']],
]);

describe('the owner', function () {
    it('holds every permission without any of them being granted', function () {
        $owner = $this->ownerOf($this->acme);

        // The Owner role itself carries no grants ...
        expect(DB::table('role_has_permissions')->where('role_id', $this->roleOf($this->acme, Role::OWNER)->id)->count())->toBe(0);

        // ... the membership's owner flag does it.
        $this->inTenant($this->acme, function () use ($owner): void {
            foreach (CORE_PERMISSIONS as $permission) {
                expect(Gate::forUser($owner)->allows($permission))->toBeTrue()
                    ->and(app(AccessChecker::class)->scopeFor($owner, $permission))->toBe(AccessScope::All);
            }
        });
    });

    it('is the owner of their own workspace only', function () {
        $owner = $this->ownerOf($this->acme);
        $this->addMember($this->globex, Role::EMPLOYEE, $owner);

        $this->inTenant($this->globex, function () use ($owner): void {
            expect(Gate::forUser($owner)->allows('core.members.view'))->toBeTrue()
                ->and(Gate::forUser($owner)->allows('core.members.manage'))->toBeFalse()
                ->and(Gate::forUser($owner)->allows('core.audit.view'))->toBeFalse();
        });
    });
});

describe('a custom role', function () {
    it('grants exactly its own permissions', function () {
        $auditors = $this->createRole($this->acme, 'Auditors', ['core.audit.view']);
        $auditor = $this->addMember($this->acme, $auditors);

        $this->actingAsMember($this->acme, $auditor);

        $this->get('http://acme.localhost/audit-log')->assertOk();
        $this->get('http://acme.localhost/team/members')->assertForbidden();
        $this->get('http://acme.localhost/team/roles')->assertForbidden();
        $this->get('http://acme.localhost/')->assertOk();
    });

    it('grants nothing when it has no permissions', function () {
        $guest = $this->addMember($this->acme, $this->createRole($this->acme, 'Guests'));

        $this->actingAsMember($this->acme, $guest);

        $this->get('http://acme.localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permissions', []));
        $this->get('http://acme.localhost/team/members')->assertForbidden();
    });
});

describe('the access checker', function () {
    beforeEach(function () {
        $this->checker = fn (): AccessChecker => app(AccessChecker::class);
        $this->manager = $this->addMember($this->acme, Role::MANAGER);
    });

    it('denies everything outside a tenant context', function () {
        expect(($this->checker)()->allows($this->manager, 'core.members.view'))->toBeFalse()
            ->and(($this->checker)()->effectivePermissions($this->manager))->toBe([])
            ->and(($this->checker)()->membership($this->manager))->toBeNull()
            ->and(Gate::forUser($this->manager)->allows('core.members.view'))->toBeFalse();
    });

    it('denies a permission no module registered, even if a grant for it exists', function () {
        $ghost = Permission::query()->create(['name' => 'core.ghosts.summon', 'guard_name' => 'web', 'module' => 'core']);

        DB::table('role_has_permissions')->insert([
            'permission_id' => $ghost->id,
            'role_id' => $this->roleOf($this->acme, Role::MANAGER)->id,
            'scope' => 'all',
        ]);

        $this->inTenant($this->acme, function (): void {
            expect(($this->checker)()->allows($this->manager, 'core.ghosts.summon'))->toBeFalse()
                ->and(array_keys(($this->checker)()->effectivePermissions($this->manager)))->not->toContain('core.ghosts.summon');
        });
    });

    it('denies someone who is not a member, or whose membership is suspended', function () {
        $outsider = $this->addMember($this->globex, Role::ADMIN);
        $suspended = $this->addMember($this->acme, Role::ADMIN, status: MembershipStatus::Suspended);

        $this->inTenant($this->acme, function () use ($outsider, $suspended): void {
            foreach ([$outsider, $suspended] as $user) {
                expect(($this->checker)()->allows($user, 'core.members.view'))->toBeFalse()
                    ->and(($this->checker)()->effectivePermissions($user))->toBe([])
                    ->and(Gate::forUser($user)->allows('core.members.view'))->toBeFalse();
            }
        });
    });

    it('does not count a role of another workspace attached to the person here', function () {
        // A stray assignment row: globex's Admin role, filed under acme.
        DB::table('model_has_roles')->insert([
            'role_id' => $this->roleOf($this->globex, Role::ADMIN)->id,
            'model_type' => 'user',
            'model_id' => $this->manager->id,
            'tenant_id' => $this->acme->id,
        ]);

        $this->inTenant($this->acme, function (): void {
            expect(($this->checker)()->allows($this->manager, 'core.roles.manage'))->toBeFalse()
                ->and(($this->checker)()->allows($this->manager, 'core.members.invite'))->toBeTrue();
        });
    });

    it('denies everything in a workspace that is not active', function () {
        $owner = $this->ownerOf($this->acme);
        $this->acme->update(['status' => 'suspended']);

        $this->inTenant($this->acme, function () use ($owner): void {
            expect(($this->checker)()->allows($owner, 'core.members.view'))->toBeFalse()
                ->and(($this->checker)()->effectivePermissions($owner))->toBe([]);
        });
    });

    it('takes the widest scope when several roles grant the same permission', function () {
        $narrow = $this->createRole($this->acme, 'Narrow', ['core.members.view']);
        $wide = $this->createRole($this->acme, 'Wide', ['core.members.view']);

        DB::table('role_has_permissions')->where('role_id', $narrow->id)->update(['scope' => 'own']);
        DB::table('role_has_permissions')->where('role_id', $wide->id)->update(['scope' => 'department']);

        $person = $this->addMember($this->acme, $narrow);

        $scope = fn (): ?AccessScope => $this->inTenant(
            $this->acme,
            fn (): ?AccessScope => app(AccessChecker::class)->scopeFor($person, 'core.members.view'),
        );

        expect($scope())->toBe(AccessScope::Own);

        $this->inTenant($this->acme, function () use ($person, $wide): void {
            $person->assignRole($wide);
            app(RoleManager::class)->flush();
        });

        expect($scope())->toBe(AccessScope::Department)
            ->and(AccessScope::Department->covers(AccessScope::Own))->toBeTrue()
            ->and(AccessScope::Own->covers(AccessScope::Branch))->toBeFalse();
    });

    it('routes module permissions through the checker and leaves other abilities alone', function () {
        Gate::define('do-something-unrelated', fn (User $user): bool => true);

        $this->inTenant($this->acme, function (): void {
            expect($this->manager->can('core.members.view'))->toBeTrue()
                ->and($this->manager->can('core.members.manage'))->toBeFalse()
                ->and($this->manager->can('do-something-unrelated'))->toBeTrue();

            // A later gate cannot grant what the checker denied.
            Gate::define('core.members.manage', fn (): bool => true);
            Gate::after(fn (): bool => true);

            expect($this->manager->can('core.members.manage'))->toBeFalse();
        });
    });
});
