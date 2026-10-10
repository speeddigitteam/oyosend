<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/*
 * How far a grant reaches is part of the grant: own, department, branch or
 * all. Only a permission its module marked scopable can be narrowed, and the
 * rule of the role editor - nobody hands out what they do not hold - applies
 * to the scope as well as to the permission.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);

    /** The scope column as stored, by permission name. */
    $this->stored = fn (Role $role): array => DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_has_permissions.role_id', $role->id)
        ->orderBy('permissions.name')
        ->pluck('role_has_permissions.scope', 'permissions.name')
        ->all();

    /** What the role manager reports, as plain strings. */
    $this->grants = fn (Role $role): array => array_map(
        static fn (AccessScope $scope): string => $scope->value,
        app(RoleManager::class)->grants($role),
    );

    /** A role made by the owner, who may grant anything at any scope. */
    $this->roleWith = fn (string $name, array $scopes): Role => $this->inTenant(
        $this->acme,
        fn (): Role => app(RoleManager::class)->create($this->owner, $name, null, array_keys($scopes), $scopes),
    );

    /** The scope a person effectively holds a permission with, as the next request would see it. */
    $this->scopeOf = function (User $user, string $permission): ?AccessScope {
        $this->app->forgetScopedInstances();

        return $this->inTenant($this->acme, fn (): ?AccessScope => app(AccessChecker::class)->scopeFor($user, $permission));
    };
});

describe('storing scopes', function () {
    it('stores the scope chosen for each grant', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.view', 'core.contacts.manage', 'core.contacts.delete', 'core.members.view'],
                'scopes' => [
                    'core.contacts.view' => 'branch',
                    'core.contacts.manage' => 'department',
                    'core.contacts.delete' => 'own',
                ],
            ])
            ->assertRedirect('http://acme.localhost/team/roles')
            ->assertSessionHasNoErrors();

        $role = $this->roleOf($this->acme, 'Sales Reps');

        expect(($this->stored)($role))->toBe([
            'core.contacts.delete' => 'own',
            'core.contacts.manage' => 'department',
            'core.contacts.view' => 'branch',
            'core.members.view' => 'all',
        ]);

        // The role manager reports the same, as scopes, in name order.
        expect(app(RoleManager::class)->grants($role))->toBe([
            'core.contacts.delete' => AccessScope::Own,
            'core.contacts.manage' => AccessScope::Department,
            'core.contacts.view' => AccessScope::Branch,
            'core.members.view' => AccessScope::All,
        ]);
    });

    it('grants a permission for every record when no scope is named for it', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Readers',
                'permissions' => ['core.contacts.view', 'core.contacts.manage'],
                'scopes' => ['core.contacts.manage' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        expect(($this->stored)($this->roleOf($this->acme, 'Readers')))->toBe([
            'core.contacts.manage' => 'own',
            'core.contacts.view' => 'all',
        ]);
    });

    it('always grants a permission that cannot be narrowed for every record, whatever is sent', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Narrow Minded',
                'permissions' => ['core.members.view', 'core.audit.view', 'core.contacts.view'],
                'scopes' => [
                    'core.members.view' => 'own',
                    'core.audit.view' => 'department',
                    'core.contacts.view' => 'own',
                ],
            ])
            ->assertSessionHasNoErrors();

        $role = $this->roleOf($this->acme, 'Narrow Minded');

        expect(($this->stored)($role))->toBe([
            'core.audit.view' => 'all',
            'core.contacts.view' => 'own',
            'core.members.view' => 'all',
        ]);

        // The same on an update.
        $this->put("http://acme.localhost/team/roles/{$role->id}", [
            'name' => 'Narrow Minded',
            'permissions' => ['core.members.view', 'core.contacts.view'],
            'scopes' => ['core.members.view' => 'branch', 'core.contacts.view' => 'department'],
        ])->assertSessionHasNoErrors();

        expect(($this->stored)($role))->toBe([
            'core.contacts.view' => 'department',
            'core.members.view' => 'all',
        ]);
    });

    it('ignores a scope named for a permission that is not selected', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Leftovers',
                'permissions' => ['core.contacts.view'],
                'scopes' => ['core.contacts.view' => 'own', 'core.contacts.delete' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        expect(($this->stored)($this->roleOf($this->acme, 'Leftovers')))->toBe(['core.contacts.view' => 'own']);
    });

    it('re-scopes an existing grant and keeps the others as they were', function () {
        $role = ($this->roleWith)('Sales Reps', ['core.contacts.view' => 'all', 'core.contacts.manage' => 'own']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.view', 'core.contacts.manage'],
                'scopes' => ['core.contacts.view' => 'department', 'core.contacts.manage' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        expect(($this->stored)($role))->toBe([
            'core.contacts.manage' => 'own',
            'core.contacts.view' => 'department',
        ]);
    });

    it('applies a narrowed grant to the people who hold the role straight away', function () {
        $role = ($this->roleWith)('Sales Reps', ['core.contacts.view' => 'all']);
        $rep = $this->addMember($this->acme, $role);

        expect(($this->scopeOf)($rep, 'core.contacts.view'))->toBe(AccessScope::All);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.view'],
                'scopes' => ['core.contacts.view' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        expect(($this->scopeOf)($rep, 'core.contacts.view'))->toBe(AccessScope::Own);
    });

    it('rejects a scope that does not exist', function (mixed $scopes) {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Broken',
                'permissions' => ['core.contacts.view'],
                'scopes' => $scopes,
            ])
            ->assertSessionHasErrors('scopes');

        expect(Role::query()->where('tenant_id', $this->acme->id)->where('name', 'Broken')->exists())->toBeFalse();
    })->with([
        'an unknown scope' => [['core.contacts.view' => 'galaxy']],
        'an empty scope' => [['core.contacts.view' => '']],
        'a scope in the wrong case' => [['core.contacts.view' => 'OWN']],
        'a scope that is not text' => [['core.contacts.view' => ['own']]],
        'an unknown scope for a permission that is not selected' => [['core.contacts.delete' => 'everything']],
        'scopes that are not a map' => ['own'],
    ]);

    it('rejects an invalid scope on an update and leaves the role as it was', function () {
        $role = ($this->roleWith)('Sales Reps', ['core.contacts.view' => 'department']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Renamed',
                'permissions' => ['core.contacts.view', 'core.contacts.manage'],
                'scopes' => ['core.contacts.view' => 'team'],
            ])
            ->assertSessionHasErrors(['scopes' => 'One of the selected scopes is not valid.']);

        expect($role->refresh()->name)->toBe('Sales Reps')
            ->and(($this->stored)($role))->toBe(['core.contacts.view' => 'department']);
    });

    it('rejects an invalid scope at the role manager too', function () {
        $this->inTenant($this->acme, function (): void {
            expect(fn () => app(RoleManager::class)->create($this->owner, 'Broken', null, ['core.contacts.view'], ['core.contacts.view' => 'galaxy']))
                ->toThrow(ValidationException::class);
        });

        expect(Role::query()->where('tenant_id', $this->acme->id)->where('name', 'Broken')->exists())->toBeFalse();
    });
});

describe('the role editor', function () {
    it('returns the scope of every grant of the role being edited', function () {
        $role = ($this->roleWith)('Sales Reps', [
            'core.contacts.view' => 'branch',
            'core.contacts.manage' => 'own',
            'core.members.view' => 'all',
        ]);

        $this->actingAsMember($this->acme, $this->admin)
            ->get("http://acme.localhost/team/roles/{$role->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Team/Roles/Edit')
                ->where('role.permissions', ['core.contacts.manage', 'core.contacts.view', 'core.members.view'])
                ->where('role.scopes', [
                    'core.contacts.manage' => 'own',
                    'core.contacts.view' => 'branch',
                    'core.members.view' => 'all',
                ]));
    });

    it('returns the scopes the Employee role starts with', function () {
        $employee = $this->roleOf($this->acme, Role::EMPLOYEE);

        $this->actingAsMember($this->acme, $this->admin)
            ->get("http://acme.localhost/team/roles/{$employee->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('role.scopes', [
                'core.apps.view' => 'all',
                'core.contacts.manage' => 'own',
                'core.contacts.view' => 'all',
                'core.members.view' => 'all',
                'core.organization.view' => 'all',
            ]));
    });

    it('tells the editor which permissions can be narrowed, and how far they may grant each', function () {
        // Holds contacts for their department only, and may not delete them at all.
        $editor = $this->addMember($this->acme, ($this->roleWith)('Role Editors', [
            'core.roles.view' => 'all',
            'core.roles.manage' => 'all',
            'core.contacts.view' => 'department',
            'core.contacts.manage' => 'own',
        ]));

        $catalogue = function (User $viewer): array {
            $permissions = [];

            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $viewer)
                ->get('http://acme.localhost/team/roles/create')
                ->assertOk()
                ->assertInertia(function (AssertableInertia $page) use (&$permissions): void {
                    $permissions = collect($page->toArray()['props']['catalogue'])
                        ->flatMap(fn (array $module): array => $module['groups'])
                        ->flatMap(fn (array $group): array => $group['permissions'])
                        ->keyBy('name')
                        ->all();
                });

            return $permissions;
        };

        $forEditor = $catalogue($editor);

        expect($forEditor['core.contacts.view'])->toMatchArray(['scopable' => true, 'grantable' => true, 'maxScope' => 'department'])
            ->and($forEditor['core.contacts.manage'])->toMatchArray(['scopable' => true, 'grantable' => true, 'maxScope' => 'own'])
            ->and($forEditor['core.contacts.delete'])->toMatchArray(['scopable' => true, 'grantable' => false, 'maxScope' => null])
            ->and($forEditor['core.roles.manage'])->toMatchArray(['scopable' => false, 'grantable' => true, 'maxScope' => 'all'])
            ->and($forEditor['core.members.view'])->toMatchArray(['scopable' => false, 'grantable' => false, 'maxScope' => null]);

        // Only the three contact permissions can be narrowed.
        expect(array_keys(array_filter($forEditor, fn (array $permission): bool => $permission['scopable'])))
            ->toEqualCanonicalizing(['core.contacts.view', 'core.contacts.manage', 'core.contacts.delete']);

        // The owner holds everything, for every record.
        expect(array_unique(array_column($catalogue($this->owner), 'maxScope')))->toBe(['all']);
    });
});

describe('you can only hand out a scope you hold', function () {
    beforeEach(function () {
        // May edit roles; holds contacts for their department, and may change only their own.
        $this->editor = $this->addMember($this->acme, ($this->roleWith)('Role Editors', [
            'core.roles.view' => 'all',
            'core.roles.manage' => 'all',
            'core.contacts.view' => 'department',
            'core.contacts.manage' => 'own',
        ]));

        $this->actingAsMember($this->acme, $this->editor);

        $this->refusal = ['permissions' => 'You can only grant or remove permissions that you hold yourself.'];
    });

    it('refuses to grant a permission more widely than the editor holds it', function (string $scope, bool $allowed) {
        $response = $this->post('http://acme.localhost/team/roles', [
            'name' => 'Helpers',
            'permissions' => ['core.contacts.view'],
            'scopes' => ['core.contacts.view' => $scope],
        ]);

        $created = Role::query()->where('tenant_id', $this->acme->id)->where('name', 'Helpers')->first();

        if ($allowed) {
            $response->assertSessionHasNoErrors();

            expect(($this->stored)($created))->toBe(['core.contacts.view' => $scope]);
        } else {
            $response->assertSessionHasErrors($this->refusal);

            // Nothing half-created is left behind.
            expect($created)->toBeNull();
        }
    })->with([
        'own, narrower than theirs' => ['own', true],
        'department, exactly theirs' => ['department', true],
        'branch, wider than theirs' => ['branch', false],
        'all, wider than theirs' => ['all', false],
    ]);

    it('refuses a grant with no scope named, because that means every record', function () {
        $this->post('http://acme.localhost/team/roles', ['name' => 'Helpers', 'permissions' => ['core.contacts.view']])
            ->assertSessionHasErrors($this->refusal);

        $this->post('http://acme.localhost/team/roles', ['name' => 'Helpers', 'permissions' => ['core.contacts.manage']])
            ->assertSessionHasErrors($this->refusal);

        expect(Role::query()->where('tenant_id', $this->acme->id)->where('name', 'Helpers')->exists())->toBeFalse();
    });

    it('refuses to widen a grant beyond what the editor holds', function () {
        $role = ($this->roleWith)('Helpers', ['core.contacts.view' => 'own']);

        $this->put("http://acme.localhost/team/roles/{$role->id}", [
            'name' => 'Helpers',
            'permissions' => ['core.contacts.view'],
            'scopes' => ['core.contacts.view' => 'branch'],
        ])->assertSessionHasErrors($this->refusal);

        expect(($this->stored)($role))->toBe(['core.contacts.view' => 'own']);

        // Up to their own scope is fine.
        $this->put("http://acme.localhost/team/roles/{$role->id}", [
            'name' => 'Helpers',
            'permissions' => ['core.contacts.view'],
            'scopes' => ['core.contacts.view' => 'department'],
        ])->assertSessionHasNoErrors();

        expect(($this->stored)($role))->toBe(['core.contacts.view' => 'department']);
    });

    it('refuses to narrow or remove a grant that reaches further than the editor\'s own', function (array $permissions, array $scopes) {
        $role = ($this->roleWith)('Seniors', ['core.contacts.view' => 'all', 'core.roles.view' => 'all']);

        $this->put("http://acme.localhost/team/roles/{$role->id}", [
            'name' => 'Seniors',
            'permissions' => $permissions,
            'scopes' => $scopes,
        ])->assertSessionHasErrors($this->refusal);

        expect(($this->stored)($role))->toBe(['core.contacts.view' => 'all', 'core.roles.view' => 'all']);
    })->with([
        'narrowed to their own scope' => [['core.contacts.view', 'core.roles.view'], ['core.contacts.view' => 'department']],
        'narrowed below their own scope' => [['core.contacts.view', 'core.roles.view'], ['core.contacts.view' => 'own']],
        'removed' => [['core.roles.view'], []],
    ]);

    it('leaves a wider grant alone while changing what the editor does hold', function () {
        $role = ($this->roleWith)('Seniors', ['core.contacts.view' => 'all', 'core.contacts.manage' => 'own']);

        $this->put("http://acme.localhost/team/roles/{$role->id}", [
            'name' => 'Seniors',
            'description' => 'Know every customer.',
            'permissions' => ['core.contacts.view', 'core.roles.view'],
            'scopes' => ['core.contacts.view' => 'all'],
        ])->assertSessionHasNoErrors();

        expect(($this->stored)($role))->toBe(['core.contacts.view' => 'all', 'core.roles.view' => 'all'])
            ->and($role->refresh()->description)->toBe('Know every customer.');
    });

    it('lets the editor narrow or remove a grant that is within their own', function () {
        $role = ($this->roleWith)('Juniors', ['core.contacts.view' => 'department', 'core.contacts.manage' => 'own']);

        $this->put("http://acme.localhost/team/roles/{$role->id}", [
            'name' => 'Juniors',
            'permissions' => ['core.contacts.view'],
            'scopes' => ['core.contacts.view' => 'own'],
        ])->assertSessionHasNoErrors();

        expect(($this->stored)($role))->toBe(['core.contacts.view' => 'own']);
    });

    it('applies to the owner too, who simply holds everything for every record', function () {
        $this->startNewBrowserSession()
            ->actingAsOwner($this->acme)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Everything',
                'permissions' => ['core.contacts.view', 'core.contacts.manage', 'core.contacts.delete'],
                'scopes' => ['core.contacts.manage' => 'branch'],
            ])
            ->assertSessionHasNoErrors();

        expect(($this->stored)($this->roleOf($this->acme, 'Everything')))->toBe([
            'core.contacts.delete' => 'all',
            'core.contacts.manage' => 'branch',
            'core.contacts.view' => 'all',
        ]);
    });
});

describe('handing out a role', function () {
    beforeEach(function () {
        $this->lead = $this->addMember($this->acme, ($this->roleWith)('Team Leads', [
            'core.members.view' => 'all',
            'core.members.manage' => 'all',
            'core.contacts.view' => 'department',
            'core.contacts.manage' => 'own',
        ]));

        $this->canAssign = function (User $actor, Role $role): bool {
            $this->app->forgetScopedInstances();

            return $this->inTenant($this->acme, fn (): bool => app(RoleManager::class)->canAssign($actor, $role));
        };
    });

    it('is allowed only when the person holds everything the role grants at least as widely', function (array $scopes, bool $expected) {
        $role = ($this->roleWith)('Candidates', $scopes);

        expect(($this->canAssign)($this->lead, $role))->toBe($expected)
            // The owner holds everything for every record; an Admin every contact permission.
            ->and(($this->canAssign)($this->owner, $role))->toBeTrue()
            ->and(($this->canAssign)($this->admin, $role))->toBeTrue();
    })->with([
        'the same grants' => [['core.contacts.view' => 'department', 'core.contacts.manage' => 'own'], true],
        'narrower grants' => [['core.contacts.view' => 'own'], true],
        'a permission that cannot be narrowed' => [['core.members.view' => 'all'], true],
        'one grant wider than theirs' => [['core.contacts.view' => 'branch'], false],
        'a grant for every record' => [['core.contacts.view' => 'all'], false],
        'a wider grant among ones they hold' => [['core.contacts.view' => 'department', 'core.contacts.manage' => 'department'], false],
        'a permission they do not hold at all' => [['core.contacts.view' => 'own', 'core.contacts.delete' => 'own'], false],
    ]);

    it('keeps the Employee role out of reach of someone who sees only their department\'s contacts', function () {
        // Employee sees every contact; the lead does not.
        expect(($this->canAssign)($this->lead, $this->roleOf($this->acme, Role::EMPLOYEE)))->toBeFalse()
            ->and(($this->canAssign)($this->admin, $this->roleOf($this->acme, Role::EMPLOYEE)))->toBeTrue();
    });

    it('offers only such roles on the members page', function () {
        ($this->roleWith)('Juniors', ['core.contacts.view' => 'own']);
        ($this->roleWith)('Seniors', ['core.contacts.view' => 'all']);

        $this->actingAsMember($this->acme, $this->lead)
            ->get('http://acme.localhost/team/members')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignableRoles', fn ($roles): bool => collect($roles)->pluck('name')->all() === ['Juniors', 'Team Leads']));
    });

    it('refuses to give someone a role that reaches further than the person assigning it', function () {
        $junior = $this->addMember($this->acme, ($this->roleWith)('Juniors', ['core.contacts.view' => 'own']));
        $seniors = ($this->roleWith)('Seniors', ['core.contacts.view' => 'all']);
        $membership = $this->membershipOf($this->acme, $junior);

        $this->actingAsMember($this->acme, $this->lead)
            ->patch("http://acme.localhost/team/members/{$membership->id}", ['role_id' => $seniors->id])
            ->assertSessionHasErrors(['role_id' => 'You cannot assign this role.']);

        expect($this->inTenant($this->acme, fn (): array => $junior->roles()->pluck('name')->all()))->toBe(['Juniors']);

        $this->patch("http://acme.localhost/team/members/{$membership->id}", ['role_id' => $this->roleOf($this->acme, 'Team Leads')->id])
            ->assertSessionHasNoErrors();

        expect($this->inTenant($this->acme, fn (): array => $junior->roles()->pluck('name')->all()))->toBe(['Team Leads']);
    });
});

describe('the audit trail', function () {
    it('records the scope of a grant that is narrower than every record', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/roles', [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.view', 'core.contacts.manage', 'core.members.view'],
                'scopes' => ['core.contacts.view' => 'department', 'core.contacts.manage' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        $entry = $this->auditTrail($this->acme, 'role.permissions_changed')->last();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('role')
            ->and($entry->subject_id)->toBe($this->roleOf($this->acme, 'Sales Reps')->id)
            ->and($entry->properties)->toEqual([
                'added' => ['core.contacts.manage', 'core.contacts.view', 'core.members.view'],
                'removed' => [],
                'scopes' => ['core.contacts.manage' => 'own', 'core.contacts.view' => 'department'],
            ]);
    });

    it('records a grant that is narrowed later, and only that grant', function () {
        $role = ($this->roleWith)('Sales Reps', ['core.contacts.view' => 'all', 'core.contacts.manage' => 'own', 'core.members.view' => 'all']);
        $before = $this->auditTrail($this->acme, 'role.permissions_changed')->count();

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.view', 'core.contacts.manage', 'core.members.view'],
                'scopes' => ['core.contacts.view' => 'branch', 'core.contacts.manage' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        $entries = $this->auditTrail($this->acme, 'role.permissions_changed');

        expect($entries)->toHaveCount($before + 1)
            ->and($entries->last()->subject_id)->toBe($role->id)
            ->and($entries->last()->properties)->toEqual([
                'added' => [],
                'removed' => [],
                'scopes' => ['core.contacts.view' => 'branch'],
            ]);
    });

    it('records a grant that is widened, back to every record included', function () {
        $role = ($this->roleWith)('Sales Reps', ['core.contacts.view' => 'own', 'core.contacts.manage' => 'own']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.view', 'core.contacts.manage'],
                'scopes' => ['core.contacts.view' => 'all', 'core.contacts.manage' => 'department'],
            ])
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, 'role.permissions_changed')->last()->properties)->toEqual([
            'added' => [],
            'removed' => [],
            'scopes' => ['core.contacts.manage' => 'department', 'core.contacts.view' => 'all'],
        ]);
    });

    it('leaves scopes out of the entry when every grant reaches every record', function () {
        ($this->roleWith)('Readers', ['core.contacts.view' => 'all', 'core.members.view' => 'all']);

        expect($this->auditTrail($this->acme, 'role.permissions_changed')->last()->properties)->toEqual([
            'added' => ['core.contacts.view', 'core.members.view'],
            'removed' => [],
        ]);
    });

    it('records nothing when the grants and their scopes are saved unchanged', function () {
        $role = ($this->roleWith)('Sales Reps', ['core.contacts.view' => 'department', 'core.contacts.manage' => 'own']);
        $before = $this->auditTrail($this->acme, 'role.permissions_changed')->count();

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/team/roles/{$role->id}", [
                'name' => 'Sales Reps',
                'permissions' => ['core.contacts.manage', 'core.contacts.view'],
                'scopes' => ['core.contacts.view' => 'department', 'core.contacts.manage' => 'own'],
            ])
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, 'role.permissions_changed'))->toHaveCount($before);
    });
});

describe('the system roles', function () {
    it('provisions the Employee role to see every contact and change only their own', function () {
        $employee = $this->roleOf($this->acme, Role::EMPLOYEE);

        expect(($this->stored)($employee))->toBe([
            'core.apps.view' => 'all',
            'core.contacts.manage' => 'own',
            'core.contacts.view' => 'all',
            'core.members.view' => 'all',
            'core.organization.view' => 'all',
        ]);

        expect(($this->grants)($employee)['core.contacts.manage'])->toBe('own');

        $person = $this->addMember($this->acme, Role::EMPLOYEE);

        expect(($this->scopeOf)($person, 'core.contacts.manage'))->toBe(AccessScope::Own)
            ->and(($this->scopeOf)($person, 'core.contacts.view'))->toBe(AccessScope::All)
            ->and(($this->scopeOf)($person, 'core.contacts.delete'))->toBeNull();
    });

    it('provisions every other grant of the system roles for every record', function (string $name) {
        expect(array_unique(array_values(($this->stored)($this->roleOf($this->acme, $name)))))->toBe(['all']);
    })->with([Role::ADMIN, Role::MANAGER]);

    it('provisions the same scopes in every workspace', function () {
        $globex = $this->createTenant('globex');

        expect(($this->stored)($this->roleOf($globex, Role::EMPLOYEE)))
            ->toBe(($this->stored)($this->roleOf($this->acme, Role::EMPLOYEE)));
    });
});
