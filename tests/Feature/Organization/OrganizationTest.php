<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Access\RecordAccess;
use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;

/*
 * The organisation chart of a workspace: flat lists of branches and of
 * departments. Most rules are the same for both, so most tests run once for
 * each: [path segment, model, name of its audit events and membership column].
 */
dataset('branches and departments', [
    'branches' => ['branches', Branch::class, 'branch'],
    'departments' => ['departments', Department::class, 'department'],
]);

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->admin = $this->addMember($this->acme, Role::ADMIN);

    /** A branch or a department of a workspace (acme unless told otherwise). */
    $this->make = fn (string $model, array $attributes = [], ?Tenant $tenant = null): Model => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): Model => $model::factory()->create($attributes),
    );

    /** The names a workspace has of one kind, in name order. */
    $this->namesOf = fn (string $model, ?Tenant $tenant = null): array => $model::forTenant($tenant ?? $this->acme)
        ->orderBy('name')
        ->pluck('name')
        ->all();

    /** Put a member of acme in a branch and a department. */
    $this->seat = fn (User $user, ?Branch $branch, ?Department $department) => $this->inTenant(
        $this->acme,
        fn () => $this->membershipOf($this->acme, $user)->update(['branch_id' => $branch?->id, 'department_id' => $department?->id]),
    );
});

it('isolates branches between tenants', function () {
    $this->assertTenantIsolated(Branch::class, fn () => Branch::factory()->create());
});

it('isolates departments between tenants', function () {
    $this->assertTenantIsolated(Department::class, fn () => Department::factory()->create());
});

describe('the organization page', function () {
    it('lists the branches and departments of this workspace with the people in each, and only them', function () {
        $dhaka = ($this->make)(Branch::class, ['name' => 'Dhaka', 'code' => 'DHK', 'phone' => '02-5550100', 'address' => 'House 12, Road 5, Dhanmondi']);
        $chattogram = ($this->make)(Branch::class, ['name' => 'Chattogram']);
        $sales = ($this->make)(Department::class, ['name' => 'Sales', 'code' => 'SAL', 'description' => 'Finds and keeps customers.']);
        $accounts = ($this->make)(Department::class, ['name' => 'Accounts']);

        ($this->make)(Branch::class, ['name' => 'Globex HQ'], $this->globex);
        ($this->make)(Department::class, ['name' => 'Globex Sales'], $this->globex);

        // Two people in Dhaka, one of them in Sales; a suspended member still sits where they sat.
        ($this->seat)($this->admin, $dhaka, $sales);
        ($this->seat)($this->addMember($this->acme, Role::EMPLOYEE, status: MembershipStatus::Suspended), $dhaka, null);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/organization')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Organization/Index')
                ->where('branches', [
                    ['id' => $chattogram->id, 'name' => 'Chattogram', 'code' => null, 'phone' => null, 'address' => null, 'members' => 0],
                    ['id' => $dhaka->id, 'name' => 'Dhaka', 'code' => 'DHK', 'phone' => '02-5550100', 'address' => 'House 12, Road 5, Dhanmondi', 'members' => 2],
                ])
                ->where('departments', [
                    ['id' => $accounts->id, 'name' => 'Accounts', 'code' => null, 'description' => null, 'members' => 0],
                    ['id' => $sales->id, 'name' => 'Sales', 'code' => 'SAL', 'description' => 'Finds and keeps customers.', 'members' => 1],
                ]));
    });

    it('is empty in a workspace that has neither', function () {
        ($this->make)(Branch::class, [], $this->globex);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/organization')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('branches', [])
                ->where('departments', []));
    });
});

describe('who may see and change the organization', function () {
    beforeEach(function () {
        $this->viewer = $this->addMember($this->acme, $this->createRole($this->acme, 'Org Viewers', ['core.organization.view']));
        $this->manager = $this->addMember($this->acme, $this->createRole($this->acme, 'Org Managers', ['core.organization.view', 'core.organization.manage']));
        // Holds something, but nothing about the organization.
        $this->stranger = $this->addMember($this->acme, $this->createRole($this->acme, 'Member Viewers', ['core.members.view']));
    });

    it('shows the page to someone who may view it, and to nobody else', function () {
        $this->actingAsMember($this->acme, $this->viewer)->get('http://acme.localhost/organization')->assertOk();

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->manager)
            ->get('http://acme.localhost/organization')
            ->assertOk();

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->stranger)
            ->get('http://acme.localhost/organization')
            ->assertForbidden();
    });

    it('lets only someone who may manage it add to it', function (string $path, string $model) {
        foreach ([$this->viewer, $this->stranger] as $person) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $person)
                ->post("http://acme.localhost/organization/{$path}", ['name' => 'Sneaked In'])
                ->assertForbidden();
        }

        expect(($this->namesOf)($model))->toBe([]);

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->manager)
            ->post("http://acme.localhost/organization/{$path}", ['name' => 'Allowed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(($this->namesOf)($model))->toBe(['Allowed']);
    })->with('branches and departments');

    it('lets only someone who may manage it change or delete what is there', function (string $path, string $model) {
        $unit = ($this->make)($model, ['name' => 'Original']);

        foreach ([$this->viewer, $this->stranger] as $person) {
            $this->startNewBrowserSession()->actingAsMember($this->acme, $person);

            $this->put("http://acme.localhost/organization/{$path}/{$unit->id}", ['name' => 'Renamed'])->assertForbidden();
            $this->delete("http://acme.localhost/organization/{$path}/{$unit->id}")->assertForbidden();
        }

        expect(($this->namesOf)($model))->toBe(['Original']);

        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->manager);

        $this->put("http://acme.localhost/organization/{$path}/{$unit->id}", ['name' => 'Renamed'])->assertSessionHasNoErrors();
        expect(($this->namesOf)($model))->toBe(['Renamed']);

        $this->delete("http://acme.localhost/organization/{$path}/{$unit->id}")->assertSessionHasNoErrors();
        expect(($this->namesOf)($model))->toBe([]);
    })->with('branches and departments');

    it('gives each system role the access it is defined with', function (string $role, bool $mayView, bool $mayManage) {
        $person = $role === Role::OWNER ? $this->ownerOf($this->acme) : $this->addMember($this->acme, $role);

        $this->actingAsMember($this->acme, $person);

        $this->get('http://acme.localhost/organization')->assertStatus($mayView ? 200 : 403);
        $this->post('http://acme.localhost/organization/branches', ['name' => 'Dhaka'])->assertStatus($mayManage ? 302 : 403);
        $this->post('http://acme.localhost/organization/departments', ['name' => 'Sales'])->assertStatus($mayManage ? 302 : 403);

        expect(($this->namesOf)(Branch::class))->toBe($mayManage ? ['Dhaka'] : [])
            ->and(($this->namesOf)(Department::class))->toBe($mayManage ? ['Sales'] : []);
    })->with([
        'owner' => [Role::OWNER, true, true],
        'admin' => [Role::ADMIN, true, true],
        'manager' => [Role::MANAGER, true, false],
        'employee' => [Role::EMPLOYEE, true, false],
    ]);
});

describe('adding', function () {
    it('adds a branch and records it', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/organization/branches', [
                'name' => 'Dhaka',
                'code' => 'DHK',
                'phone' => '02-5550100',
                'address' => 'House 12, Road 5, Dhanmondi',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $branch = Branch::forTenant($this->acme)->sole();

        expect($branch->tenant_id)->toBe($this->acme->id)
            ->and($branch->only(['name', 'code', 'phone', 'address']))->toBe([
                'name' => 'Dhaka',
                'code' => 'DHK',
                'phone' => '02-5550100',
                'address' => 'House 12, Road 5, Dhanmondi',
            ]);

        $entry = $this->auditTrail($this->acme, 'branch.created')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('branch')
            ->and($entry->subject_id)->toBe($branch->id)
            ->and($entry->subject_label)->toBe('Dhaka')
            ->and($entry->properties['attributes'])->toMatchArray([
                'name' => 'Dhaka',
                'code' => 'DHK',
                'phone' => '02-5550100',
                'address' => 'House 12, Road 5, Dhanmondi',
            ]);
    });

    it('adds a department and records it', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/organization/departments', [
                'name' => 'Sales',
                'code' => 'SAL',
                'description' => 'Finds and keeps customers.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $department = Department::forTenant($this->acme)->sole();

        expect($department->tenant_id)->toBe($this->acme->id)
            ->and($department->only(['name', 'code', 'description']))->toBe([
                'name' => 'Sales',
                'code' => 'SAL',
                'description' => 'Finds and keeps customers.',
            ]);

        $entry = $this->auditTrail($this->acme, 'department.created')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('department')
            ->and($entry->subject_id)->toBe($department->id)
            ->and($entry->subject_label)->toBe('Sales')
            ->and($entry->properties['attributes'])->toMatchArray([
                'name' => 'Sales',
                'code' => 'SAL',
                'description' => 'Finds and keeps customers.',
            ]);
    });

    it('needs nothing but a name, and stores what is left empty as nothing', function (string $path, string $model) {
        $this->actingAsMember($this->acme, $this->admin)
            ->post("http://acme.localhost/organization/{$path}", ['name' => '  Head Office  ', 'code' => '', 'phone' => '', 'address' => '', 'description' => ''])
            ->assertSessionHasNoErrors();

        $unit = $model::forTenant($this->acme)->sole();

        // A branch has no description and a department no phone or address: look at the columns that exist.
        expect($unit->name)->toBe('Head Office')
            ->and(array_filter(Arr::only($unit->getAttributes(), ['code', 'phone', 'address', 'description'])))->toBe([]);
    })->with('branches and departments');

    it('never takes the workspace from the request', function (string $path, string $model) {
        $this->actingAsMember($this->acme, $this->admin)
            ->post("http://acme.localhost/organization/{$path}", ['name' => 'Planted', 'tenant_id' => $this->globex->id])
            ->assertSessionHasNoErrors();

        expect(($this->namesOf)($model))->toBe(['Planted'])
            ->and(($this->namesOf)($model, $this->globex))->toBe([]);
    })->with('branches and departments');
});

describe('changing', function () {
    it('changes a branch and records what changed', function () {
        $branch = ($this->make)(Branch::class, ['name' => 'Dhaka', 'code' => 'DHK']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/organization/branches/{$branch->id}", [
                'name' => 'Dhaka Head Office',
                'code' => 'DHK',
                'phone' => '02-5550100',
                'address' => null,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($branch->refresh()->only(['name', 'code', 'phone', 'address']))->toBe([
            'name' => 'Dhaka Head Office',
            'code' => 'DHK',
            'phone' => '02-5550100',
            'address' => null,
        ]);

        $entry = $this->auditTrail($this->acme, 'branch.updated')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_id)->toBe($branch->id)
            ->and($entry->subject_label)->toBe('Dhaka Head Office')
            ->and($entry->properties)->toEqual([
                'old' => ['name' => 'Dhaka', 'phone' => null],
                'attributes' => ['name' => 'Dhaka Head Office', 'phone' => '02-5550100'],
            ]);
    });

    it('changes a department and records what changed', function () {
        $department = ($this->make)(Department::class, ['name' => 'Sales', 'code' => 'SAL', 'description' => 'Finds customers.']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/organization/departments/{$department->id}", [
                'name' => 'Sales',
                'code' => null,
                'description' => 'Finds and keeps customers.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($department->refresh()->only(['name', 'code', 'description']))->toBe([
            'name' => 'Sales',
            'code' => null,
            'description' => 'Finds and keeps customers.',
        ]);

        expect($this->auditTrail($this->acme, 'department.updated')->sole()->properties)->toEqual([
            'old' => ['code' => 'SAL', 'description' => 'Finds customers.'],
            'attributes' => ['code' => null, 'description' => 'Finds and keeps customers.'],
        ]);
    });

    it('records nothing when it is saved as it was', function (string $path, string $model, string $key) {
        $unit = ($this->make)($model, ['name' => 'Unchanged', 'code' => 'SAME']);

        $this->actingAsMember($this->acme, $this->admin)
            ->put("http://acme.localhost/organization/{$path}/{$unit->id}", ['name' => 'Unchanged', 'code' => 'SAME'])
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, "{$key}.updated"))->toHaveCount(0);
    })->with('branches and departments');

    it('keeps the people in it where they are', function (string $path, string $model, string $key) {
        $unit = ($this->make)($model, ['name' => 'Before']);
        $membership = $this->membershipOf($this->acme, $this->admin);

        $this->inTenant($this->acme, fn () => $membership->update(["{$key}_id" => $unit->id]));

        $this->actingAsOwner($this->acme)
            ->put("http://acme.localhost/organization/{$path}/{$unit->id}", ['name' => 'After'])
            ->assertSessionHasNoErrors();

        expect($membership->refresh()->getAttribute("{$key}_id"))->toBe($unit->id);
    })->with('branches and departments');
});

describe('deleting', function () {
    beforeEach(function () {
        $this->dhaka = ($this->make)(Branch::class, ['name' => 'Dhaka']);
        $this->sales = ($this->make)(Department::class, ['name' => 'Sales']);

        $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);
        $this->membership = $this->membershipOf($this->acme, $this->employee);

        ($this->seat)($this->employee, $this->dhaka, $this->sales);

        // A record of that branch and department: it belongs to the employee.
        $this->contact = $this->inTenant($this->acme, function (): Contact {
            $contact = Contact::factory()->make();
            $contact->assignOwner($this->membershipOf($this->acme, $this->employee))->save();

            return $contact;
        });
    });

    it('deletes a branch: its people and its records stay, and no longer belong to a branch', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/organization/branches/{$this->dhaka->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(($this->namesOf)(Branch::class))->toBe([]);

        // The person is still a member, with the same role and department - just in no branch.
        $this->membership->refresh();

        expect($this->membership->branch_id)->toBeNull()
            ->and($this->membership->department_id)->toBe($this->sales->id)
            ->and($this->membership->status)->toBe(MembershipStatus::Active)
            ->and($this->inTenant($this->acme, fn (): array => $this->employee->roles()->pluck('name')->all()))->toBe(['Employee']);

        // So is the record, with the same owner.
        $this->contact->refresh();

        expect($this->contact->branch_id)->toBeNull()
            ->and($this->contact->department_id)->toBe($this->sales->id)
            ->and($this->contact->owner_id)->toBe($this->membership->id)
            ->and($this->contact->deleted_at)->toBeNull();

        $entry = $this->auditTrail($this->acme, 'branch.deleted')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('branch')
            ->and($entry->subject_id)->toBe($this->dhaka->id)
            ->and($entry->subject_label)->toBe('Dhaka');
    });

    it('deletes a department: its people and its records stay, and no longer belong to a department', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/organization/departments/{$this->sales->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(($this->namesOf)(Department::class))->toBe([]);

        $this->membership->refresh();

        expect($this->membership->department_id)->toBeNull()
            ->and($this->membership->branch_id)->toBe($this->dhaka->id)
            ->and($this->membership->status)->toBe(MembershipStatus::Active)
            ->and($this->inTenant($this->acme, fn (): array => $this->employee->roles()->pluck('name')->all()))->toBe(['Employee']);

        $this->contact->refresh();

        expect($this->contact->department_id)->toBeNull()
            ->and($this->contact->branch_id)->toBe($this->dhaka->id)
            ->and($this->contact->owner_id)->toBe($this->membership->id)
            ->and($this->contact->deleted_at)->toBeNull();

        $entry = $this->auditTrail($this->acme, 'department.deleted')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('department')
            ->and($entry->subject_id)->toBe($this->sales->id)
            ->and($entry->subject_label)->toBe('Sales');
    });

    it('takes its records out of reach of the people who reached them through it', function (string $path, string $model, string $key) {
        // Sees the contacts of their branch (and so of their department), and sits where the employee sits.
        $colleague = $this->addMember($this->acme, $this->inTenant($this->acme, fn (): Role => app(RoleManager::class)->create(
            $this->ownerOf($this->acme),
            'Colleagues',
            null,
            ['core.contacts.view'],
            ['core.contacts.view' => 'branch'],
        )));

        // Shares only the one that is about to be deleted.
        ($this->seat)($colleague, $key === 'branch' ? $this->dhaka : null, $key === 'department' ? $this->sales : null);

        $reaches = function () use ($colleague): bool {
            $this->app->forgetScopedInstances();

            return $this->inTenant($this->acme, fn (): bool => app(RecordAccess::class)
                ->constrain(Contact::query(), $colleague, 'core.contacts.view')
                ->whereKey($this->contact->id)
                ->exists());
        };

        expect($reaches())->toBeTrue();

        $unit = $key === 'branch' ? $this->dhaka : $this->sales;

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/organization/{$path}/{$unit->id}")
            ->assertSessionHasNoErrors();

        expect($reaches())->toBeFalse();
    })->with('branches and departments');

    it('leaves the other branches and departments, and the people in them, alone', function (string $path, string $model, string $key) {
        $other = ($this->make)($model, ['name' => 'Other']);
        $elsewhere = $this->membershipOf($this->acme, $this->admin);

        $this->inTenant($this->acme, fn () => $elsewhere->update(["{$key}_id" => $other->id]));

        $unit = $key === 'branch' ? $this->dhaka : $this->sales;

        $this->actingAsOwner($this->acme)
            ->delete("http://acme.localhost/organization/{$path}/{$unit->id}")
            ->assertSessionHasNoErrors();

        expect(($this->namesOf)($model))->toBe(['Other'])
            ->and($elsewhere->refresh()->getAttribute("{$key}_id"))->toBe($other->id);
    })->with('branches and departments');
});

describe('validation', function () {
    it('rejects what does not fit', function (string $path, string $model, string $key, array $input, string $field) {
        $unit = ($this->make)($model, ['name' => 'Existing']);

        $this->actingAsMember($this->acme, $this->admin);

        $this->post("http://acme.localhost/organization/{$path}", ['name' => 'Valid Name', ...$input])->assertSessionHasErrors($field);
        $this->put("http://acme.localhost/organization/{$path}/{$unit->id}", ['name' => 'Valid Name', ...$input])->assertSessionHasErrors($field);

        expect(($this->namesOf)($model))->toBe(['Existing']);
    })->with('branches and departments')->with([
        'no name' => [['name' => ''], 'name'],
        'a name that is only spaces' => [['name' => '   '], 'name'],
        'a name that is too long' => [['name' => str_repeat('a', 121)], 'name'],
        'a name that is not text' => [['name' => ['Dhaka']], 'name'],
        'a code that is too long' => [['code' => str_repeat('A', 31)], 'code'],
        'a code that is not text' => [['code' => ['DHK']], 'code'],
    ]);

    it('rejects branch details that do not fit', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/organization/branches', ['name' => 'Dhaka', ...$input])
            ->assertSessionHasErrors($field);

        expect(($this->namesOf)(Branch::class))->toBe([]);
    })->with([
        'a phone number that is too long' => [['phone' => str_repeat('1', 41)], 'phone'],
        'an address that is too long' => [['address' => str_repeat('a', 501)], 'address'],
    ]);

    it('rejects a department description that is too long', function () {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/organization/departments', ['name' => 'Sales', 'description' => str_repeat('a', 256)])
            ->assertSessionHasErrors('description');

        expect(($this->namesOf)(Department::class))->toBe([]);
    });

    it('accepts values of exactly the longest length', function () {
        $this->actingAsMember($this->acme, $this->admin);

        $this->post('http://acme.localhost/organization/branches', [
            'name' => str_repeat('n', 120),
            'code' => str_repeat('C', 30),
            'phone' => str_repeat('1', 40),
            'address' => str_repeat('a', 500),
        ])->assertSessionHasNoErrors();

        $this->post('http://acme.localhost/organization/departments', [
            'name' => str_repeat('n', 120),
            'code' => str_repeat('C', 30),
            'description' => str_repeat('d', 255),
        ])->assertSessionHasNoErrors();

        expect(Branch::forTenant($this->acme)->count())->toBe(1)
            ->and(Department::forTenant($this->acme)->count())->toBe(1);
    });

    it('keeps names and codes unique within a workspace but free across workspaces', function (string $path, string $model) {
        ($this->make)($model, ['name' => 'Dhaka', 'code' => 'DHK']);

        $this->actingAsMember($this->acme, $this->admin);

        $url = "http://acme.localhost/organization/{$path}";

        $this->post($url, ['name' => 'Dhaka'])->assertSessionHasErrors('name');
        // The database compares without regard to case, so validation has to as well.
        $this->post($url, ['name' => 'dhaka'])->assertSessionHasErrors('name');
        $this->post($url, ['name' => 'Dhaka North', 'code' => 'DHK'])->assertSessionHasErrors('code');
        $this->post($url, ['name' => 'Dhaka North', 'code' => 'dhk'])->assertSessionHasErrors('code');

        expect(($this->namesOf)($model))->toBe(['Dhaka']);

        // Any number of them may go without a code.
        $this->post($url, ['name' => 'Sylhet'])->assertSessionHasNoErrors();
        $this->post($url, ['name' => 'Khulna', 'code' => ''])->assertSessionHasNoErrors();

        expect(($this->namesOf)($model))->toBe(['Dhaka', 'Khulna', 'Sylhet']);

        // The same name and code in another workspace are somebody else's business.
        $this->startNewBrowserSession()
            ->actingAsOwner($this->globex)
            ->post("http://globex.localhost/organization/{$path}", ['name' => 'Dhaka', 'code' => 'DHK'])
            ->assertSessionHasNoErrors();

        expect(($this->namesOf)($model, $this->globex))->toBe(['Dhaka'])
            ->and(($this->namesOf)($model))->toBe(['Dhaka', 'Khulna', 'Sylhet']);
    })->with('branches and departments');

    it('lets one keep its own name and code, and refuses those of another', function (string $path, string $model) {
        $dhaka = ($this->make)($model, ['name' => 'Dhaka', 'code' => 'DHK']);
        $chattogram = ($this->make)($model, ['name' => 'Chattogram', 'code' => 'CTG']);

        $this->actingAsMember($this->acme, $this->admin);

        $this->put("http://acme.localhost/organization/{$path}/{$dhaka->id}", ['name' => 'Dhaka', 'code' => 'DHK'])->assertSessionHasNoErrors();

        $this->put("http://acme.localhost/organization/{$path}/{$chattogram->id}", ['name' => 'Dhaka', 'code' => 'CTG'])->assertSessionHasErrors('name');
        $this->put("http://acme.localhost/organization/{$path}/{$chattogram->id}", ['name' => 'Chattogram', 'code' => 'DHK'])->assertSessionHasErrors('code');

        expect($chattogram->refresh()->only(['name', 'code']))->toBe(['name' => 'Chattogram', 'code' => 'CTG']);
    })->with('branches and departments');

    it('does not confuse a branch with a department of the same name', function () {
        ($this->make)(Branch::class, ['name' => 'Support', 'code' => 'SUP']);

        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/organization/departments', ['name' => 'Support', 'code' => 'SUP'])
            ->assertSessionHasNoErrors();

        expect(($this->namesOf)(Department::class))->toBe(['Support']);
    });
});

describe('branches and departments of another workspace', function () {
    it('cannot be changed or deleted', function (string $path, string $model, string $key, string $method) {
        $unit = ($this->make)($model, ['name' => 'Ours']);

        $this->assertNotFoundFromOtherTenant(
            $method,
            "/organization/{$path}/{$unit->id}",
            $this->acme,
            ['name' => 'Hijacked'],
            intruder: $this->globex,
        );
    })->with('branches and departments')->with([
        'update' => ['PUT'],
        'delete' => ['DELETE'],
    ]);

    it('are untouched by an attempt from here', function (string $path, string $model) {
        $theirs = ($this->make)($model, ['name' => 'Theirs'], $this->globex);

        $this->actingAsOwner($this->acme);

        $this->put("http://acme.localhost/organization/{$path}/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->delete("http://acme.localhost/organization/{$path}/{$theirs->id}")->assertNotFound();

        expect(($this->namesOf)($model, $this->globex))->toBe(['Theirs']);
    })->with('branches and departments');

    it('cannot be reached by someone who belongs to both workspaces either', function (string $path, string $model) {
        // An admin here who is a plain employee over there.
        $this->addMember($this->globex, Role::EMPLOYEE, $this->admin);
        $theirs = ($this->make)($model, ['name' => 'Theirs'], $this->globex);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/organization/{$path}/{$theirs->id}")
            ->assertNotFound();

        // And over there they are an employee: they see it, and may not change it.
        $this->startNewBrowserSession()
            ->actingAsMember($this->globex, $this->admin)
            ->delete("http://globex.localhost/organization/{$path}/{$theirs->id}")
            ->assertForbidden();

        expect(($this->namesOf)($model, $this->globex))->toBe(['Theirs']);
    })->with('branches and departments');
});
