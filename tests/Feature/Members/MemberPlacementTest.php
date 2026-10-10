<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Where a member sits: one branch and one department, each optional. It is
 * changed through the same request as a role or a status, and follows the
 * same rules - with one exception, the owner, who places themselves.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);
    $this->membership = $this->membershipOf($this->acme, $this->employee);

    [$this->dhaka, $this->chattogram, $this->sales, $this->support] = $this->inTenant($this->acme, fn (): array => [
        Branch::factory()->create(['name' => 'Dhaka']),
        Branch::factory()->create(['name' => 'Chattogram']),
        Department::factory()->create(['name' => 'Sales']),
        Department::factory()->create(['name' => 'Support']),
    ]);

    [$this->theirBranch, $this->theirDepartment] = $this->inTenant($this->globex, fn (): array => [
        Branch::factory()->create(['name' => 'Globex HQ']),
        Department::factory()->create(['name' => 'Globex Sales']),
    ]);

    /** Where a member sits now, as stored: [branch id, department id]. */
    $this->placementOf = function (TenantMembership $membership): array {
        $membership->refresh();

        return [$membership->branch_id, $membership->department_id];
    };

    $this->seat = fn (TenantMembership $membership, ?Branch $branch, ?Department $department) => $this->inTenant(
        $this->acme,
        fn () => $membership->update(['branch_id' => $branch?->id, 'department_id' => $department?->id]),
    );

    $this->memberUrl = fn (TenantMembership $membership): string => "http://acme.localhost/team/members/{$membership->id}";

    /**
     * May manage members, and holds what the Employee role holds - nobody
     * manages a member who holds a permission they lack - but far less than an Admin.
     */
    $this->teamLead = fn (): User => $this->addMember($this->acme, $this->createRole($this->acme, 'Team Leads', [
        'core.apps.view', 'core.members.view', 'core.members.manage', 'core.organization.view', 'core.contacts.view', 'core.contacts.manage',
    ]));
});

describe('placing a member', function () {
    it('puts them in a branch and a department, and records old and new values', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(($this->placementOf)($this->membership))->toBe([$this->dhaka->id, $this->sales->id]);

        $entry = $this->auditTrail($this->acme, 'tenant_membership.updated')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('membership')
            ->and($entry->subject_id)->toBe($this->membership->id)
            ->and($entry->subject_label)->toBe($this->employee->email)
            ->and($entry->properties)->toEqual([
                'old' => ['branch_id' => null, 'department_id' => null],
                'attributes' => ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id],
            ]);
    });

    it('changes only what is sent', function () {
        ($this->seat)($this->membership, $this->dhaka, $this->sales);

        $this->actingAsMember($this->acme, $this->admin);

        $this->patch(($this->memberUrl)($this->membership), ['department_id' => $this->support->id])->assertSessionHasNoErrors();
        expect(($this->placementOf)($this->membership))->toBe([$this->dhaka->id, $this->support->id]);

        $this->patch(($this->memberUrl)($this->membership), ['branch_id' => $this->chattogram->id])->assertSessionHasNoErrors();
        expect(($this->placementOf)($this->membership))->toBe([$this->chattogram->id, $this->support->id]);
    });

    it('takes a member out of a branch or a department when nothing is sent for it', function (mixed $nothing) {
        ($this->seat)($this->membership, $this->dhaka, $this->sales);

        $this->actingAsMember($this->acme, $this->admin);

        $this->patch(($this->memberUrl)($this->membership), ['branch_id' => $nothing])->assertSessionHasNoErrors();
        expect(($this->placementOf)($this->membership))->toBe([null, $this->sales->id]);

        $this->patch(($this->memberUrl)($this->membership), ['branch_id' => $nothing, 'department_id' => $nothing])->assertSessionHasNoErrors();
        expect(($this->placementOf)($this->membership))->toBe([null, null]);
    })->with([
        'null' => [null],
        'an empty value' => [''],
    ]);

    it('accepts the request the way the page sends it', function () {
        ($this->seat)($this->membership, $this->dhaka, null);

        $this->actingAsMember($this->acme, $this->admin)
            ->withHeaders(['X-Inertia' => 'true'])
            ->patchJson(($this->memberUrl)($this->membership), ['branch_id' => null, 'department_id' => $this->sales->id])
            ->assertRedirect();

        expect(($this->placementOf)($this->membership))->toBe([null, $this->sales->id]);
    });

    it('can change the role and the placement in one request', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), [
                'role_id' => $this->roleOf($this->acme, Role::MANAGER)->id,
                'branch_id' => $this->chattogram->id,
                'department_id' => $this->support->id,
            ])
            ->assertSessionHasNoErrors();

        expect(($this->placementOf)($this->membership))->toBe([$this->chattogram->id, $this->support->id])
            ->and($this->inTenant($this->acme, fn (): array => $this->employee->roles()->pluck('name')->all()))->toBe(['Manager']);
    });

    it('leaves the role and the status of the member alone', function () {
        $this->membership->update(['status' => MembershipStatus::Suspended]);

        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), ['branch_id' => $this->dhaka->id])
            ->assertSessionHasNoErrors();

        expect($this->membership->refresh()->status)->toBe(MembershipStatus::Suspended)
            ->and($this->membership->branch_id)->toBe($this->dhaka->id)
            ->and($this->inTenant($this->acme, fn (): array => $this->employee->roles()->pluck('name')->all()))->toBe(['Employee']);
    });

    it('places the person in this workspace only', function () {
        $this->addMember($this->globex, Role::EMPLOYEE, $this->employee);

        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id])
            ->assertSessionHasNoErrors();

        expect(($this->placementOf)($this->membershipOf($this->globex, $this->employee)))->toBe([null, null]);
    });
});

describe('a branch or a department that is not this workspace\'s', function () {
    it('is not found, and nothing is changed', function (array $input) {
        ($this->seat)($this->membership, $this->chattogram, $this->support);

        $ids = [
            'their branch' => $this->theirBranch->id,
            'their department' => $this->theirDepartment->id,
            'our branch' => $this->dhaka->id,
            'our department' => $this->sales->id,
            'no such id' => (string) Str::ulid(),
        ];

        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), array_map(fn (string $key): string => $ids[$key], $input))
            ->assertNotFound();

        expect(($this->placementOf)($this->membership))->toBe([$this->chattogram->id, $this->support->id]);
    })->with([
        'a branch of another workspace' => [['branch_id' => 'their branch']],
        'a department of another workspace' => [['department_id' => 'their department']],
        'both of another workspace' => [['branch_id' => 'their branch', 'department_id' => 'their department']],
        'our branch with their department' => [['branch_id' => 'our branch', 'department_id' => 'their department']],
        'their branch with our department' => [['branch_id' => 'their branch', 'department_id' => 'our department']],
        'a branch that does not exist' => [['branch_id' => 'no such id']],
        'a department that does not exist' => [['department_id' => 'no such id']],
        // The id of a department is not the id of a branch.
        'a department where a branch belongs' => [['branch_id' => 'our department']],
    ]);

    it('cannot be given to their members by someone who belongs to both workspaces', function () {
        // An admin in both; the branch is acme's, the member is globex's.
        $this->addMember($this->globex, Role::ADMIN, $this->admin);
        $theirs = $this->membershipOf($this->globex, $this->addMember($this->globex, Role::EMPLOYEE));

        $this->actingAsMember($this->globex, $this->admin)
            ->patch("http://globex.localhost/team/members/{$theirs->id}", ['branch_id' => $this->dhaka->id])
            ->assertNotFound();

        expect(($this->placementOf)($theirs))->toBe([null, null]);
    });

    it('rejects an id of the wrong shape', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), $input)
            ->assertSessionHasErrors($field);

        expect(($this->placementOf)($this->membership))->toBe([null, null]);
    })->with([
        'a short branch id' => [['branch_id' => 'dhaka'], 'branch_id'],
        'a short department id' => [['department_id' => 'sales'], 'department_id'],
        'a branch id that is a list' => [['branch_id' => ['01J0000000000000000000000A']], 'branch_id'],
        'a department id that is a number' => [['department_id' => 12345], 'department_id'],
    ]);
});

describe('who may place whom', function () {
    it('takes the permission to manage members', function () {
        // A Manager sees the members and holds everything an Employee does, but does not manage them.
        $manager = $this->addMember($this->acme, Role::MANAGER);

        $this->actingAsMember($this->acme, $manager)
            ->patch(($this->memberUrl)($this->membership), ['branch_id' => $this->dhaka->id])
            ->assertForbidden();

        expect(($this->placementOf)($this->membership))->toBe([null, null]);

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, ($this->teamLead)())
            ->patch(($this->memberUrl)($this->membership), ['branch_id' => $this->dhaka->id])
            ->assertSessionHasNoErrors();

        expect(($this->placementOf)($this->membership))->toBe([$this->dhaka->id, null]);
    });

    it('does not let someone place a member who holds a permission they lack', function () {
        $adminMembership = $this->membershipOf($this->acme, $this->admin);

        $this->actingAsMember($this->acme, ($this->teamLead)())
            ->patch(($this->memberUrl)($adminMembership), ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id])
            ->assertSessionHasErrors(['member' => 'You cannot manage a member who holds permissions you do not.']);

        expect(($this->placementOf)($adminMembership))->toBe([null, null])
            ->and($this->auditTrail($this->acme, 'tenant_membership.updated'))->toHaveCount(0);
    });

    it('lets someone who holds everything the member does place them', function () {
        $secondAdmin = $this->addMember($this->acme, Role::ADMIN);
        $adminMembership = $this->membershipOf($this->acme, $this->admin);

        $this->actingAsMember($this->acme, $secondAdmin)
            ->patch(($this->memberUrl)($adminMembership), ['department_id' => $this->support->id])
            ->assertSessionHasNoErrors();

        expect(($this->placementOf)($adminMembership))->toBe([null, $this->support->id]);
    });

    it('never lets anyone but the owner place themselves', function () {
        foreach ([$this->admin, ($this->teamLead)()] as $person) {
            $own = $this->membershipOf($this->acme, $person);

            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $person)
                ->patch(($this->memberUrl)($own), ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id])
                ->assertSessionHasErrors(['member' => 'You cannot change your own membership.']);

            expect(($this->placementOf)($own))->toBe([null, null]);
        }
    });

    it('lets the owner be placed by the owner only', function () {
        $ownerMembership = $this->membershipOf($this->acme, $this->owner);

        // An admin holds everything but billing; the owner is still out of reach.
        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($ownerMembership), ['branch_id' => $this->dhaka->id])
            ->assertSessionHasErrors(['member' => 'The workspace owner cannot be changed here.']);

        expect(($this->placementOf)($ownerMembership))->toBe([null, null]);

        $this->startNewBrowserSession()
            ->actingAsOwner($this->acme)
            ->patch(($this->memberUrl)($ownerMembership), ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(($this->placementOf)($ownerMembership))->toBe([$this->dhaka->id, $this->sales->id])
            ->and($this->auditTrail($this->acme, 'tenant_membership.updated')->sole()->actor_id)->toBe($this->owner->id);
    });

    it('lets the owner change where they sit and nothing else about themselves', function () {
        $ownerMembership = $this->membershipOf($this->acme, $this->owner);

        $this->actingAsOwner($this->acme);

        $this->patch(($this->memberUrl)($ownerMembership), [
            'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id,
            'branch_id' => $this->dhaka->id,
        ])->assertSessionHasErrors('member');

        $this->patch(($this->memberUrl)($ownerMembership), ['status' => 'suspended', 'department_id' => $this->sales->id])
            ->assertSessionHasErrors('member');

        // A request that is refused in part is refused as a whole.
        expect(($this->placementOf)($ownerMembership))->toBe([null, null])
            ->and($ownerMembership->is_owner)->toBeTrue()
            ->and($ownerMembership->status)->toBe(MembershipStatus::Active)
            ->and($this->inTenant($this->acme, fn (): array => $this->owner->roles()->pluck('name')->all()))->toBe(['Owner']);
    });

    it('changes nothing at all when one part of the request is refused', function () {
        // A valid new role and status, and a branch that is not this workspace's.
        $this->actingAsMember($this->acme, $this->admin)
            ->patch(($this->memberUrl)($this->membership), [
                'role_id' => $this->roleOf($this->acme, Role::MANAGER)->id,
                'status' => 'suspended',
                'branch_id' => $this->theirBranch->id,
            ])
            ->assertNotFound();

        expect(($this->placementOf)($this->membership))->toBe([null, null])
            ->and($this->membership->status)->toBe(MembershipStatus::Active)
            ->and($this->inTenant($this->acme, fn (): array => $this->employee->roles()->pluck('name')->all()))->toBe(['Employee'])
            ->and($this->auditTrail($this->acme, 'member.role_changed'))->toHaveCount(0)
            ->and($this->auditTrail($this->acme, 'tenant_membership.updated'))->toHaveCount(0);
    });
});

describe('the members list', function () {
    it('shows where each member sits', function () {
        ($this->seat)($this->membership, $this->dhaka, $this->sales);
        ($this->seat)($this->membershipOf($this->acme, $this->admin), null, $this->support);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/members')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Core/Pages/Team/Members');

                $rows = collect($page->toArray()['props']['members']['data'])->keyBy('email');

                expect($rows[$this->employee->email]['branch'])->toBe(['id' => $this->dhaka->id, 'name' => 'Dhaka'])
                    ->and($rows[$this->employee->email]['department'])->toBe(['id' => $this->sales->id, 'name' => 'Sales'])
                    ->and($rows[$this->admin->email]['branch'])->toBeNull()
                    ->and($rows[$this->admin->email]['department'])->toBe(['id' => $this->support->id, 'name' => 'Support'])
                    ->and($rows[$this->owner->email]['branch'])->toBeNull()
                    ->and($rows[$this->owner->email]['department'])->toBeNull();
            });
    });

    it('offers the branches and departments of this workspace, by name, and only them', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/members')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('branches', [
                    ['id' => $this->chattogram->id, 'name' => 'Chattogram'],
                    ['id' => $this->dhaka->id, 'name' => 'Dhaka'],
                ])
                ->where('departments', [
                    ['id' => $this->sales->id, 'name' => 'Sales'],
                    ['id' => $this->support->id, 'name' => 'Support'],
                ]));

        $this->startNewBrowserSession()
            ->actingAsOwner($this->globex)
            ->get('http://globex.localhost/team/members')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('branches', [['id' => $this->theirBranch->id, 'name' => 'Globex HQ']])
                ->where('departments', [['id' => $this->theirDepartment->id, 'name' => 'Globex Sales']]));
    });

    it('offers nothing in a workspace that has neither', function () {
        $initech = $this->createTenant('initech');

        $this->actingAsOwner($initech)
            ->get('http://initech.localhost/team/members')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('branches', [])
                ->where('departments', []));
    });

    it('tells the list whom the viewer may place', function () {
        $lead = ($this->teamLead)();

        $placeable = fn (): array => collect($this->get('http://acme.localhost/team/members')->assertOk()->inertiaProps('members.data'))
            ->mapWithKeys(fn (array $row): array => [$row['email'] => $row['placeable']])
            ->all();

        $this->actingAsMember($this->acme, $lead);

        expect($placeable())->toEqual([
            $this->owner->email => false,
            $this->admin->email => false,
            $this->employee->email => true,
            // Oneself.
            $lead->email => false,
        ]);

        // Seeing the list is not the same as managing it.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->employee);

        expect(array_filter($placeable()))->toBe([]);

        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->admin);

        expect($placeable())->toEqual([
            $this->owner->email => false,
            $this->admin->email => false,
            $this->employee->email => true,
            $lead->email => true,
        ]);

        // The owner is the one person who may place themselves - and may not otherwise manage their own row.
        $this->startNewBrowserSession()->actingAsOwner($this->acme);

        expect($placeable())->toEqual([
            $this->owner->email => true,
            $this->admin->email => true,
            $this->employee->email => true,
            $lead->email => true,
        ]);

        $ownRow = collect($this->get('http://acme.localhost/team/members')->inertiaProps('members.data'))->firstWhere('email', $this->owner->email);

        expect($ownRow)->toMatchArray(['isOwner' => true, 'isSelf' => true, 'manageable' => false, 'placeable' => true]);
    });
});

describe('members of another workspace', function () {
    it('cannot be placed', function () {
        $this->assertNotFoundFromOtherTenant(
            'PATCH',
            "/team/members/{$this->membership->id}",
            $this->acme,
            ['branch_id' => $this->dhaka->id, 'department_id' => $this->sales->id],
            intruder: $this->globex,
        );
    });

    it('are not placed by an attempt from here, even in a branch of their own workspace', function () {
        $theirs = $this->membershipOf($this->globex, $this->addMember($this->globex, Role::EMPLOYEE));

        $this->actingAsOwner($this->acme);

        $this->patch("http://acme.localhost/team/members/{$theirs->id}", ['branch_id' => $this->theirBranch->id])->assertNotFound();
        $this->patch("http://acme.localhost/team/members/{$theirs->id}", ['branch_id' => $this->dhaka->id])->assertNotFound();

        expect(($this->placementOf)($theirs))->toBe([null, null]);
    });
});
