<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Events\MemberRemoved;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);
    $this->membership = $this->membershipOf($this->acme, $this->employee);

    $this->rolesOf = fn (User $user, $tenant = null): array => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): array => $user->roles()->pluck('name')->all(),
    );
});

describe('the members list', function () {
    it('lists the people of this workspace, and only them', function () {
        $this->enableTwoFactor($this->employee);
        $outsider = $this->addMember($this->globex, Role::ADMIN);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/members')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($outsider): void {
                $page->component('Core/Pages/Team/Members')
                    ->where('members.total', 3)
                    ->where('filters', ['search' => '']);

                $rows = collect($page->toArray()['props']['members']['data'])->keyBy('email');

                expect($rows->keys()->all())->not->toContain($outsider->email)
                    // The owner is listed first.
                    ->and($rows->keys()->first())->toBe($this->owner->email)
                    ->and($rows[$this->owner->email])->toMatchArray(['isOwner' => true, 'isSelf' => false, 'status' => 'active'])
                    ->and($rows[$this->owner->email]['role']['name'])->toBe('Owner')
                    ->and($rows[$this->admin->email])->toMatchArray(['isOwner' => false, 'isSelf' => true, 'twoFactorEnabled' => false])
                    ->and($rows[$this->admin->email]['role'])->toBe(['id' => $this->roleOf($this->acme, Role::ADMIN)->id, 'name' => 'Admin'])
                    ->and($rows[$this->employee->email])->toMatchArray([
                        'id' => $this->membership->id,
                        'name' => $this->employee->name,
                        'twoFactorEnabled' => true,
                    ])
                    // Never the account's own id, nor anything secret about it.
                    ->and(json_encode($rows))->not->toContain($this->employee->id, 'password', 'two_factor_secret', 'remember_token');
            });
    });

    it('shows a person the role they have here, not the one they have elsewhere', function () {
        $this->addMember($this->globex, Role::ADMIN, $this->employee);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/members')
            ->assertInertia(function (AssertableInertia $page): void {
                $row = collect($page->toArray()['props']['members']['data'])->firstWhere('email', $this->employee->email);

                expect($row['role']['name'])->toBe('Employee');
            });
    });

    it('searches by name and email within the workspace', function () {
        $this->employee->update(['name' => 'Imran Hossain', 'email' => 'imran@acme.test']);
        $this->addMember($this->globex, Role::EMPLOYEE, User::factory()->create(['name' => 'Imran Elsewhere', 'email' => 'imran@globex.test']));

        $emails = function (string $query): array {
            $found = [];

            $this->actingAsMember($this->acme, $this->admin)
                ->get('http://acme.localhost/team/members?search='.urlencode($query))
                ->assertOk()
                ->assertInertia(function (AssertableInertia $page) use (&$found): void {
                    $found = array_column($page->toArray()['props']['members']['data'], 'email');
                });

            return $found;
        };

        expect($emails('imran'))->toBe(['imran@acme.test'])
            ->and($emails('Hossain'))->toBe(['imran@acme.test'])
            ->and($emails('@acme.test'))->toBe(['imran@acme.test'])
            ->and($emails('globex'))->toBe([])
            ->and($emails('  '))->toHaveCount(3);
    });

    it('searches for what was typed, wildcard characters included', function () {
        $find = fn (string $term): int => $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/members?search='.urlencode($term))
            ->assertOk()
            ->inertiaProps('members.total');

        // On their own these would match everyone if they were passed through as wildcards.
        expect($find('%'))->toBe(0)
            ->and($find('_'))->toBe(0)
            ->and($find('@'))->toBe(3);
    });

    it('rejects a malformed search instead of failing', function () {
        $this->actingAsMember($this->acme, $this->admin);

        $this->get('http://acme.localhost/team/members?search[]=a')->assertSessionHasErrors('search');
        $this->get('http://acme.localhost/team/members?search='.str_repeat('a', 101))->assertSessionHasErrors('search');
    });

    it('offers only the roles the viewer is allowed to hand out', function (string $role, array $expected) {
        $viewer = $role === Role::OWNER ? $this->owner : $this->addMember($this->acme, $role);

        $this->actingAsMember($this->acme, $viewer)
            ->get('http://acme.localhost/team/members')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignableRoles', fn ($roles): bool => collect($roles)->pluck('name')->all() === $expected));
    })->with([
        'owner' => [Role::OWNER, ['Admin', 'Employee', 'Manager']],
        'admin' => [Role::ADMIN, ['Admin', 'Employee', 'Manager']],
        'manager' => [Role::MANAGER, ['Employee', 'Manager']],
        'employee' => [Role::EMPLOYEE, ['Employee']],
    ]);

    it('paginates', function () {
        foreach (range(1, 25) as $ignored) {
            $this->addMember($this->acme, Role::EMPLOYEE);
        }

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/team/members')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('members.total', 28)
                ->where('members.per_page', 25)
                ->has('members.data', 25));

        $this->get('http://acme.localhost/team/members?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('members.data', 3));
    });
});

describe('changing a role', function () {
    it('replaces the member\'s role and records the change', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['role_id' => $this->roleOf($this->acme, Role::MANAGER)->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(($this->rolesOf)($this->employee))->toBe(['Manager']);

        $entry = $this->auditTrail($this->acme, 'member.role_changed')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('membership')
            ->and($entry->subject_id)->toBe($this->membership->id)
            ->and($entry->subject_label)->toBe($this->employee->email)
            ->and($entry->properties)->toEqual(['old' => ['Employee'], 'attributes' => ['Manager']]);
    });

    it('only changes the role in this workspace', function () {
        $this->addMember($this->globex, Role::ADMIN, $this->employee);

        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['role_id' => $this->roleOf($this->acme, Role::MANAGER)->id]);

        expect(($this->rolesOf)($this->employee))->toBe(['Manager'])
            ->and(($this->rolesOf)($this->employee, $this->globex))->toBe(['Admin']);
    });

    it('does not let someone hand out a role above their own', function () {
        // May manage members, but holds nothing an Admin role needs beyond that.
        $lead = $this->addMember($this->acme, $this->createRole($this->acme, 'Team Leads', ['core.members.view', 'core.members.manage']));

        $this->actingAsMember($this->acme, $lead)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['role_id' => $this->roleOf($this->acme, Role::ADMIN)->id])
            ->assertSessionHasErrors(['role_id' => 'You cannot assign this role.']);

        expect(($this->rolesOf)($this->employee))->toBe(['Employee']);
    });

    it('never makes anyone a second owner', function () {
        foreach ([$this->admin, $this->owner] as $actor) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $actor)
                ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['role_id' => $this->roleOf($this->acme, Role::OWNER)->id])
                ->assertSessionHasErrors('role_id');
        }

        expect(($this->rolesOf)($this->employee))->toBe(['Employee'])
            ->and($this->membership->refresh()->is_owner)->toBeFalse();
    });

    it('does not accept a role of another workspace', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['role_id' => $this->roleOf($this->globex, Role::ADMIN)->id])
            ->assertNotFound();

        expect(($this->rolesOf)($this->employee))->toBe(['Employee'])
            ->and(DB::table('model_has_roles')->where('model_id', $this->employee->id)->count())->toBe(1);
    });

    it('validates the request', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", $input)
            ->assertSessionHasErrors($field);

        expect(($this->rolesOf)($this->employee))->toBe(['Employee'])
            ->and($this->membership->refresh()->status)->toBe(MembershipStatus::Active);
    })->with([
        'role id of the wrong shape' => [['role_id' => 'abc'], 'role_id'],
        'empty role id' => [['role_id' => ''], 'role_id'],
        'unknown status' => [['status' => 'banned'], 'status'],
        'empty status' => [['status' => ''], 'status'],
    ]);
});

describe('suspending and reactivating', function () {
    it('suspends a member, which locks them out, and records old and new values', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['status' => 'suspended'])
            ->assertSessionHasNoErrors();

        expect($this->membership->refresh()->status)->toBe(MembershipStatus::Suspended);

        $entry = $this->auditTrail($this->acme, 'tenant_membership.updated')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_id)->toBe($this->membership->id)
            ->and($entry->properties)->toEqual(['old' => ['status' => 'active'], 'attributes' => ['status' => 'suspended']]);

        $this->startNewBrowserSession();
        $this->post('http://acme.localhost/login', ['email' => $this->employee->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
    });

    it('reactivates a member, who can sign in again with the same role', function () {
        $this->membership->update(['status' => MembershipStatus::Suspended]);

        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['status' => 'active'])
            ->assertSessionHasNoErrors();

        expect($this->membership->refresh()->status)->toBe(MembershipStatus::Active)
            ->and(($this->rolesOf)($this->employee))->toBe(['Employee']);

        $this->startNewBrowserSession();
        $this->post('http://acme.localhost/login', ['email' => $this->employee->email, 'password' => 'password'])
            ->assertRedirect('http://acme.localhost');
    });

    it('can change role and status in one request', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", [
                'role_id' => $this->roleOf($this->acme, Role::MANAGER)->id,
                'status' => 'suspended',
            ])
            ->assertSessionHasNoErrors();

        expect(($this->rolesOf)($this->employee))->toBe(['Manager'])
            ->and($this->membership->refresh()->status)->toBe(MembershipStatus::Suspended);
    });

    it('does not suspend the person anywhere else', function () {
        $this->addMember($this->globex, Role::EMPLOYEE, $this->employee);

        $this->actingAsMember($this->acme, $this->admin)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['status' => 'suspended']);

        expect($this->membershipOf($this->globex, $this->employee)->status)->toBe(MembershipStatus::Active);
    });
});

describe('removing', function () {
    it('removes the membership and this workspace\'s roles, and nothing else', function () {
        Event::fake([MemberRemoved::class]);

        $this->addMember($this->globex, Role::MANAGER, $this->employee);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/team/members/{$this->membership->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(TenantMembership::withoutTenancy()->whereKey($this->membership->id)->exists())->toBeFalse()
            ->and(($this->rolesOf)($this->employee))->toBe([])
            // The account and the person's other workspace are untouched.
            ->and(User::query()->whereKey($this->employee->id)->exists())->toBeTrue()
            ->and($this->membershipOf($this->globex, $this->employee)->status)->toBe(MembershipStatus::Active)
            ->and(($this->rolesOf)($this->employee, $this->globex))->toBe(['Manager']);

        $entry = $this->auditTrail($this->acme, 'tenant_membership.deleted')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_id)->toBe($this->membership->id)
            ->and($entry->subject_label)->toBe($this->employee->email);

        Event::assertDispatched(MemberRemoved::class, fn (MemberRemoved $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'membership_id' => $this->membership->id,
                'user_id' => $this->employee->id,
                'email' => $this->employee->email,
            ]);

        // No longer able to sign in here; can be invited again.
        $this->startNewBrowserSession();
        $this->post('http://acme.localhost/login', ['email' => $this->employee->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
    });
});

describe('protected memberships', function () {
    it('never lets the owner be changed, suspended or removed here - not even by the owner', function () {
        $ownerMembership = $this->membershipOf($this->acme, $this->owner);

        foreach ([$this->admin, $this->owner] as $actor) {
            $this->startNewBrowserSession()->actingAsMember($this->acme, $actor);

            $this->patch("http://acme.localhost/team/members/{$ownerMembership->id}", ['role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id])
                ->assertSessionHasErrors(['member' => 'The workspace owner cannot be changed here.']);
            $this->patch("http://acme.localhost/team/members/{$ownerMembership->id}", ['status' => 'suspended'])
                ->assertSessionHasErrors('member');
            $this->delete("http://acme.localhost/team/members/{$ownerMembership->id}")
                ->assertSessionHasErrors('member');
        }

        $ownerMembership->refresh();

        expect($ownerMembership->is_owner)->toBeTrue()
            ->and($ownerMembership->status)->toBe(MembershipStatus::Active)
            ->and(($this->rolesOf)($this->owner))->toBe(['Owner']);
    });

    it('never lets someone change, suspend or remove themselves', function () {
        $own = $this->membershipOf($this->acme, $this->admin);

        $this->actingAsMember($this->acme, $this->admin);

        $this->patch("http://acme.localhost/team/members/{$own->id}", ['role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id])
            ->assertSessionHasErrors(['member' => 'You cannot change your own membership.']);
        $this->patch("http://acme.localhost/team/members/{$own->id}", ['status' => 'suspended'])
            ->assertSessionHasErrors('member');
        $this->delete("http://acme.localhost/team/members/{$own->id}")
            ->assertSessionHasErrors('member');

        expect($own->refresh()->status)->toBe(MembershipStatus::Active)
            ->and(($this->rolesOf)($this->admin))->toBe(['Admin']);

        // Still signed in and working.
        $this->get('http://acme.localhost/team/members')->assertOk();
    });
});

describe('members who hold more than the person managing them', function () {
    beforeEach(function () {
        // May manage members, but holds far less than an Admin does.
        $this->lead = $this->addMember($this->acme, $this->createRole($this->acme, 'Team Leads', ['core.members.view', 'core.members.manage']));
        $this->adminMembership = $this->membershipOf($this->acme, $this->admin);
    });

    it('cannot be demoted, suspended or removed', function (string $method, ?string $change) {
        Event::fake([MemberRemoved::class]);

        $data = match ($change) {
            'role' => ['role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id],
            'status' => ['status' => 'suspended'],
            default => [],
        };

        $this->actingAsMember($this->acme, $this->lead)
            ->{$method}("http://acme.localhost/team/members/{$this->adminMembership->id}", $data)
            ->assertSessionHasErrors(['member' => 'You cannot manage a member who holds permissions you do not.']);

        expect($this->adminMembership->refresh()->status)->toBe(MembershipStatus::Active)
            ->and(($this->rolesOf)($this->admin))->toBe(['Admin'])
            ->and($this->auditTrail($this->acme, 'member.role_changed'))->toHaveCount(0);

        Event::assertNotDispatched(MemberRemoved::class);
    })->with([
        'demoted' => ['patch', 'role'],
        'suspended' => ['patch', 'status'],
        'removed' => ['delete', null],
    ]);

    it('leaves the people below them manageable', function () {
        $this->actingAsMember($this->acme, $this->lead)
            ->patch("http://acme.localhost/team/members/{$this->membership->id}", ['status' => 'suspended'])
            ->assertSessionHasNoErrors();

        expect($this->membership->refresh()->status)->toBe(MembershipStatus::Suspended);
    });

    it('can be managed by someone who holds everything they do', function () {
        $secondAdmin = $this->addMember($this->acme, Role::ADMIN);

        $this->actingAsMember($this->acme, $secondAdmin)
            ->patch("http://acme.localhost/team/members/{$this->adminMembership->id}", ['status' => 'suspended'])
            ->assertSessionHasNoErrors();

        expect($this->adminMembership->refresh()->status)->toBe(MembershipStatus::Suspended);
    });

    it('tells the list which rows the viewer may act on', function () {
        $manageable = fn (): array => collect($this->get('http://acme.localhost/team/members')->assertOk()->inertiaProps('members.data'))
            ->mapWithKeys(fn (array $row): array => [$row['email'] => $row['manageable']])
            ->all();

        $this->actingAsMember($this->acme, $this->lead);

        expect($manageable())->toBe([
            $this->owner->email => false,
            $this->admin->email => false,
            $this->employee->email => true,
            // Oneself.
            $this->lead->email => false,
        ]);

        // Seeing the list is not the same as managing it.
        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->employee);

        expect(array_filter($manageable()))->toBe([]);

        $this->startNewBrowserSession()->actingAsOwner($this->acme);

        expect($manageable())->toBe([
            $this->owner->email => false,
            $this->admin->email => true,
            $this->employee->email => true,
            $this->lead->email => true,
        ]);
    });
});

describe('members of another workspace', function () {
    it('cannot be changed or removed', function (string $method, array $data) {
        $this->assertNotFoundFromOtherTenant(
            $method,
            "/team/members/{$this->membership->id}",
            $this->acme,
            $data,
            intruder: $this->globex,
        );
    })->with([
        'update' => ['PATCH', ['status' => 'suspended']],
        'delete' => ['DELETE', []],
    ]);

    it('are untouched by an attempt from here', function () {
        $theirs = $this->membershipOf($this->globex, $this->addMember($this->globex, Role::EMPLOYEE));

        $this->actingAsOwner($this->acme);

        $this->patch("http://acme.localhost/team/members/{$theirs->id}", ['status' => 'suspended'])->assertNotFound();
        $this->patch("http://acme.localhost/team/members/{$theirs->id}", ['role_id' => $this->roleOf($this->acme, Role::ADMIN)->id])->assertNotFound();
        $this->delete("http://acme.localhost/team/members/{$theirs->id}")->assertNotFound();

        expect($theirs->refresh()->status)->toBe(MembershipStatus::Active)
            ->and(TenantMembership::forTenant($this->globex)->count())->toBe(2);
    });

    it('cannot be reached by someone who belongs to both workspaces either', function () {
        // An admin here who is a plain employee over there.
        $this->addMember($this->globex, Role::EMPLOYEE, $this->admin);
        $theirs = $this->membershipOf($this->globex, $this->ownerOf($this->globex));

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/team/members/{$theirs->id}")
            ->assertNotFound();

        // And over there they are an employee: no managing at all.
        $this->startNewBrowserSession()
            ->actingAsMember($this->globex, $this->admin)
            ->delete("http://globex.localhost/team/members/{$theirs->id}")
            ->assertForbidden();
    });
});
