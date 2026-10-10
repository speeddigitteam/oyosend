<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->admin = $this->addMember($this->acme, Role::ADMIN);

    $this->granted = fn (Role $role): array => app(RoleManager::class)->grantedNames($role);
    $this->rolesOf = fn ($tenant): array => Role::query()->where('tenant_id', $tenant->id)->orderBy('name')->pluck('name')->all();
});

describe('listing and forms', function () {
    it('lists the workspace\'s own roles with member and permission counts', function () {
        $this->createRole($this->acme, 'Auditors', ['core.audit.view', 'core.members.view']);
        $this->createRole($this->globex, 'Globex Only', ['core.members.view']);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/roles')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Core/Pages/Team/Roles/Index')->where('totalPermissions', 14);

                $roles = collect($page->toArray()['props']['roles'])->keyBy('name');

                expect($roles->keys()->sort()->values()->all())->toBe(['Admin', 'Auditors', 'Employee', 'Manager', 'Owner'])
                    ->and($roles['Owner'])->toMatchArray(['isSystem' => true, 'isOwner' => true, 'members' => 1, 'permissions' => 0])
                    ->and($roles['Admin'])->toMatchArray(['isSystem' => true, 'isOwner' => false, 'members' => 1, 'permissions' => 12])
                    ->and($roles['Employee'])->toMatchArray(['members' => 0, 'permissions' => 4])
                    ->and($roles['Auditors'])->toMatchArray(['isSystem' => false, 'members' => 0, 'permissions' => 2]);
            });
    });

    it('lists system roles from the most powerful down, then the workspace\'s own by name', function () {
        $this->createRole($this->acme, 'Support', ['core.members.view']);
        $this->createRole($this->acme, 'Auditors', ['core.audit.view']);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/roles')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roles', fn ($roles): bool => collect($roles)->pluck('name')->all() === ['Owner', 'Admin', 'Manager', 'Employee', 'Auditors', 'Support']));
    });

    it('shows the editor a catalogue of what this person can grant', function () {
        $editor = $this->addMember($this->acme, $this->createRole($this->acme, 'Role Editors', ['core.roles.view', 'core.roles.manage']));

        $this->actingAsMember($this->acme, $editor)
            ->get('http://acme.localhost/team/roles/create')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Core/Pages/Team/Roles/Edit')->where('role', null);

                $catalogue = $page->toArray()['props']['catalogue'];

                expect(array_column($catalogue, 'module'))->toBe(['core']);

                $permissions = collect($catalogue[0]['groups'])->flatMap(fn (array $group): array => $group['permissions'])->keyBy('name');

                expect($permissions)->toHaveCount(14)
                    ->and($permissions['core.roles.manage'])->toMatchArray(['grantable' => true, 'sensitive' => true])
                    ->and($permissions['core.roles.view'])->toMatchArray(['grantable' => true, 'sensitive' => false])
                    ->and($permissions['core.audit.view'])->toMatchArray(['grantable' => false, 'sensitive' => true])
                    ->and($permissions['core.members.view']['grantable'])->toBeFalse();
            });
    });

    it('shows a role with its current permissions for editing', function () {
        $role = $this->createRole($this->acme, 'Auditors', ['core.audit.view']);

        $this->actingAsMember($this->acme, $this->admin)
            ->get("http://acme.localhost/team/roles/{$role->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('role.id', $role->id)
                ->where('role.name', 'Auditors')
                ->where('role.isSystem', false)
                ->where('role.isOwner', false)
                ->where('role.permissions', ['core.audit.view']));
    });
});

describe('creating', function () {
    it('creates a role with the chosen permissions and records it', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Recruiters',
                'description' => 'Bring people in.',
                'permissions' => ['core.members.view', 'core.members.invite', 'core.members.view'],
            ])
            ->assertRedirect('http://acme.localhost/team/roles')
            ->assertSessionHasNoErrors();

        $role = $this->roleOf($this->acme, 'Recruiters');

        expect($role->tenant_id)->toBe($this->acme->id)
            ->and($role->is_system)->toBeFalse()
            ->and($role->guard_name)->toBe('web')
            ->and($role->description)->toBe('Bring people in.')
            ->and(($this->granted)($role))->toBe(['core.members.invite', 'core.members.view'])
            ->and($this->auditTrail($this->acme, 'role.created')->last()->subject_id)->toBe($role->id)
            ->and($this->auditTrail($this->acme, 'role.permissions_changed')->last()->properties)->toEqual([
                'added' => ['core.members.invite', 'core.members.view'],
                'removed' => [],
            ]);
    });

    it('keeps role names unique within a workspace but free across workspaces', function () {
        $this->actingAsMember($this->acme, $this->admin);

        $this->post('http://acme.localhost/team/roles', ['name' => 'Sales'])->assertSessionHasNoErrors();
        $this->post('http://acme.localhost/team/roles', ['name' => 'Sales'])->assertSessionHasErrors('name');
        $this->post('http://acme.localhost/team/roles', ['name' => 'Admin'])->assertSessionHasErrors('name');

        expect(Role::query()->where('tenant_id', $this->acme->id)->where('name', 'Sales')->count())->toBe(1);

        // The same name in another workspace is somebody else's business.
        $this->startNewBrowserSession()
            ->actingAsOwner($this->globex)
            ->post('http://globex.localhost/team/roles', ['name' => 'Sales', 'permissions' => ['core.audit.view']])
            ->assertSessionHasNoErrors();

        expect(($this->rolesOf)($this->globex))->toContain('Sales')
            ->and(($this->granted)($this->roleOf($this->acme, 'Sales')))->toBe([])
            ->and(($this->granted)($this->roleOf($this->globex, 'Sales')))->toBe(['core.audit.view']);
    });

    it('validates the role', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', ['name' => 'Valid Name', ...$input])
            ->assertSessionHasErrors($field);

        expect(($this->rolesOf)($this->acme))->toHaveCount(4);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'name too long' => [['name' => str_repeat('a', 81)], 'name'],
        'description too long' => [['description' => str_repeat('a', 256)], 'description'],
        'permissions not a list' => [['permissions' => 'core.members.view'], 'permissions'],
        'a permission that does not exist' => [['permissions' => ['core.members.view', 'core.everything']], 'permissions'],
        'a permission of another module' => [['permissions' => ['crm.leads.view']], 'permissions'],
        'a platform ability' => [['permissions' => ['platform.tenants.manage']], 'permissions'],
    ]);
});

describe('you can only hand out what you hold', function () {
    beforeEach(function () {
        // May edit roles, but holds nothing else.
        $this->editor = $this->addMember(
            $this->acme,
            $this->createRole($this->acme, 'Role Editors', ['core.roles.view', 'core.roles.manage']),
        );

        $this->actingAsMember($this->acme, $this->editor);
    });

    it('refuses to create a role with a permission the editor does not hold', function () {
        $this->post('http://acme.localhost/team/roles', ['name' => 'Sneaky', 'permissions' => ['core.roles.view', 'core.audit.view']])
            ->assertSessionHasErrors(['permissions' => 'You can only grant or remove permissions that you hold yourself.']);

        // Nothing half-created is left behind.
        expect(($this->rolesOf)($this->acme))->not->toContain('Sneaky');

        $this->post('http://acme.localhost/team/roles', ['name' => 'Fine', 'permissions' => ['core.roles.view']])
            ->assertSessionHasNoErrors();

        expect(($this->granted)($this->roleOf($this->acme, 'Fine')))->toBe(['core.roles.view']);
    });

    it('refuses to add a permission the editor does not hold to an existing role, including their own', function () {
        $own = $this->roleOf($this->acme, 'Role Editors');

        $this->put("http://acme.localhost/team/roles/{$own->id}", [
            'name' => 'Role Editors',
            'permissions' => ['core.roles.view', 'core.roles.manage', 'core.members.manage'],
        ])->assertSessionHasErrors('permissions');

        expect(($this->granted)($own))->toBe(['core.roles.manage', 'core.roles.view']);

        $this->inTenant($this->acme, fn () => expect($this->editor->can('core.members.manage'))->toBeFalse());
    });

    it('refuses to remove a permission the editor does not hold', function () {
        $employee = $this->roleOf($this->acme, Role::EMPLOYEE);

        $granted = ['core.contacts.manage', 'core.contacts.view', 'core.members.view', 'core.organization.view'];

        // Stripping what Employee holds: the editor holds none of it.
        $this->put("http://acme.localhost/team/roles/{$employee->id}", ['name' => 'Employee', 'permissions' => []])
            ->assertSessionHasErrors('permissions');

        expect(($this->granted)($employee))->toBe($granted);

        // Leaving it all in place - scopes included - while adding one of their own is fine.
        $this->put("http://acme.localhost/team/roles/{$employee->id}", [
            'name' => 'Employee',
            'permissions' => [...$granted, 'core.roles.view'],
            'scopes' => ['core.contacts.manage' => 'own'],
        ])->assertSessionHasNoErrors();

        expect(($this->granted)($employee))->toBe([...$granted, 'core.roles.view']);
    });

    it('applies to the owner too, who simply holds everything', function () {
        $this->startNewBrowserSession()
            ->actingAsOwner($this->acme)
            ->post('http://acme.localhost/team/roles', ['name' => 'Everything', 'permissions' => [
                'core.audit.view', 'core.members.invite', 'core.members.manage', 'core.members.view',
                'core.roles.manage', 'core.roles.view', 'core.settings.manage',
            ]])
            ->assertSessionHasNoErrors();

        expect(($this->granted)($this->roleOf($this->acme, 'Everything')))->toHaveCount(7);
    });
});

describe('updating', function () {
    it('renames a custom role and replaces its permissions', function () {
        $role = $this->createRole($this->acme, 'Auditors', ['core.audit.view']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Compliance',
                'description' => 'Reads the trail.',
                'permissions' => ['core.audit.view', 'core.members.view'],
            ])
            ->assertRedirect('http://acme.localhost/team/roles')
            ->assertSessionHasNoErrors();

        $role->refresh();

        expect($role->name)->toBe('Compliance')
            ->and($role->description)->toBe('Reads the trail.')
            ->and(($this->granted)($role))->toBe(['core.audit.view', 'core.members.view']);

        $renamed = $this->auditTrail($this->acme, 'role.updated')->last();

        expect($renamed->properties)->toEqual([
            'old' => ['name' => 'Auditors', 'description' => null],
            'attributes' => ['name' => 'Compliance', 'description' => 'Reads the trail.'],
        ]);
    });

    it('lets a role keep its own name, and refuses a name another role has', function () {
        $role = $this->createRole($this->acme, 'Auditors');

        $this->actingAsMember($this->acme, $this->admin);

        $this->put("http://acme.localhost/team/roles/{$role->id}", ['name' => 'Auditors'])->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/team/roles/{$role->id}", ['name' => 'Manager'])->assertSessionHasErrors('name');

        expect($role->refresh()->name)->toBe('Auditors');
    });

    it('changes the permissions of a system role but never its name', function () {
        $manager = $this->roleOf($this->acme, Role::MANAGER);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$manager->id}", [
                'name' => 'Team Leads',
                'permissions' => ['core.members.view'],
            ])
            ->assertSessionHasNoErrors();

        expect($manager->refresh()->name)->toBe('Manager')
            ->and($manager->is_system)->toBeTrue()
            ->and(($this->granted)($manager))->toBe(['core.members.view']);
    });

    it('never changes the Owner role', function () {
        $owner = $this->roleOf($this->acme, Role::OWNER);

        foreach ([$this->admin, $this->ownerOf($this->acme)] as $actor) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $actor)
                ->put("http://acme.localhost/team/roles/{$owner->id}", [
                    'name' => 'Owner',
                    'description' => 'Tampered',
                    'permissions' => ['core.members.view'],
                ])
                ->assertSessionHasErrors(['role' => 'The Owner role always has full access and cannot be changed.']);
        }

        expect($owner->refresh()->description)->not->toBe('Tampered')
            ->and(($this->granted)($owner))->toBe([]);
    });
});

describe('deleting', function () {
    it('deletes an unused custom role with its grants and records it', function () {
        $role = $this->createRole($this->acme, 'Temporary', ['core.audit.view']);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/team/roles/{$role->id}")
            ->assertRedirect('http://acme.localhost/team/roles')
            ->assertSessionHasNoErrors();

        expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
            ->and(DB::table('role_has_permissions')->where('role_id', $role->id)->count())->toBe(0)
            ->and($this->auditTrail($this->acme, 'role.deleted')->sole()->subject_label)->toBe('Temporary');
    });

    it('never deletes a system role', function (string $name) {
        $role = $this->roleOf($this->acme, $name);

        $this->actingAsOwner($this->acme)
            ->delete("http://acme.localhost/team/roles/{$role->id}")
            ->assertSessionHasErrors(['role' => 'System roles cannot be deleted.']);

        expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
    })->with([Role::OWNER, Role::ADMIN, Role::MANAGER, Role::EMPLOYEE]);

    it('does not delete a role somebody still holds', function () {
        $role = $this->createRole($this->acme, 'In Use');
        $this->addMember($this->acme, $role);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/team/roles/{$role->id}")
            ->assertSessionHasErrors('role');

        expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
    });

    it('does not delete a role a pending invitation is waiting for, but ignores settled ones', function () {
        $role = $this->createRole($this->acme, 'Promised');
        [$invitation] = $this->createInvitation($this->acme, 'new@example.test', $role);

        $this->actingAsMember($this->acme, $this->admin);

        $this->delete("http://acme.localhost/team/roles/{$role->id}")->assertSessionHasErrors('role');
        expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();

        $this->inTenant($this->acme, fn () => $invitation->update(['revoked_at' => now()]));

        $this->delete("http://acme.localhost/team/roles/{$role->id}")->assertSessionHasNoErrors();
        expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
    });
});

describe('roles of another workspace', function () {
    beforeEach(function () {
        $this->theirs = $this->createRole($this->globex, 'Globex Custom', ['core.audit.view']);
    });

    it('cannot be opened, changed or deleted', function (string $method, string $suffix) {
        $this->assertNotFoundFromOtherTenant(
            $method,
            "/team/roles/{$this->theirs->id}{$suffix}",
            $this->globex,
            ['name' => 'Hijacked', 'permissions' => []],
            intruder: $this->acme,
        );
    })->with([
        'edit' => ['GET', '/edit'],
        'update' => ['PUT', ''],
        'delete' => ['DELETE', ''],
    ]);

    it('are untouched by an attempt from here', function () {
        $this->actingAsOwner($this->acme);

        $this->put("http://acme.localhost/team/roles/{$this->theirs->id}", ['name' => 'Hijacked', 'permissions' => []])->assertNotFound();
        $this->delete("http://acme.localhost/team/roles/{$this->theirs->id}")->assertNotFound();

        expect($this->theirs->refresh()->name)->toBe('Globex Custom')
            ->and(($this->granted)($this->theirs))->toBe(['core.audit.view']);
    });

    it('are refused by the role manager itself, whoever calls it', function () {
        $owner = $this->ownerOf($this->acme);

        $this->inTenant($this->acme, function () use ($owner): void {
            $manager = app(RoleManager::class);

            expect($manager->canAssign($owner, $this->theirs))->toBeFalse();

            foreach ([
                fn () => $manager->update($owner, $this->theirs, 'Hijacked', null, []),
                fn () => $manager->syncPermissions($owner, $this->theirs, []),
                fn () => $manager->delete($this->theirs),
            ] as $attempt) {
                expect($attempt)->toThrow(NotFoundHttpException::class);
            }
        });

        expect(($this->granted)($this->theirs))->toBe(['core.audit.view']);
    });

    it('does not resolve through route-model binding, with or without a tenant', function () {
        expect($this->inTenant($this->acme, fn () => (new Role)->resolveRouteBinding($this->theirs->id)))->toBeNull()
            ->and($this->inTenant($this->globex, fn () => (new Role)->resolveRouteBinding($this->theirs->id))?->is($this->theirs))->toBeTrue();

        expect(fn () => (new Role)->resolveRouteBinding($this->theirs->id))
            ->toThrow(MissingTenantContextException::class);
    });
});

it('rejects permissions the workspace is not entitled to at the role manager too', function () {
    $owner = $this->ownerOf($this->acme);

    $this->inTenant($this->acme, function () use ($owner): void {
        expect(fn () => app(RoleManager::class)->create($owner, 'Nope', null, ['crm.leads.view']))
            ->toThrow(ValidationException::class);
    });

    expect(($this->rolesOf)($this->acme))->not->toContain('Nope');
});

it('counts members per role inside the workspace only', function () {
    // One person, Employee here and Employee there.
    $shared = $this->addMember($this->acme, Role::EMPLOYEE);
    $this->addMember($this->globex, Role::EMPLOYEE, $shared);
    $this->addMember($this->globex, Role::EMPLOYEE);

    $this->actingAsMember($this->acme, $this->admin)
        ->get('http://acme.localhost/team/roles')
        ->assertInertia(function (AssertableInertia $page): void {
            $roles = collect($page->toArray()['props']['roles'])->keyBy('name');

            expect($roles['Employee']['members'])->toBe(1);
        });
});
