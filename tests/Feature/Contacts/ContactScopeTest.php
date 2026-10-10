<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use Inertia\Testing\AssertableInertia;

/*
 * What the scope of a grant - own, department, branch or all - does to the
 * contact directory, over HTTP: which rows are listed, which can be changed,
 * and who may hand a record to someone else.
 *
 * Records are made before anyone signs in and are given their owner
 * explicitly: a record made by a signed-in member becomes theirs.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');

    $this->owner = $this->ownerOf($this->acme);
    $this->manager = $this->addMember($this->acme, Role::MANAGER);
    $this->alice = $this->addMember($this->acme, Role::EMPLOYEE, User::factory()->create(['name' => 'Alice Akter']));
    $this->bob = $this->addMember($this->acme, Role::EMPLOYEE, User::factory()->create(['name' => 'Bob Biswas']));

    $this->membership = fn (User $user) => $this->membershipOf($this->acme, $user);

    $this->department = fn (string $name): Department => $this->inTenant($this->acme, fn (): Department => Department::factory()->create(['name' => $name]));
    $this->branch = fn (string $name): Branch => $this->inTenant($this->acme, fn (): Branch => Branch::factory()->create(['name' => $name]));

    /** Put a member in a department and a branch (or take them out of both). */
    $this->place = function (User $user, ?Department $department = null, ?Branch $branch = null): void {
        $this->inTenant($this->acme, function () use ($user, $department, $branch): void {
            $this->membershipOf($this->acme, $user)->update([
                'department_id' => $department?->id,
                'branch_id' => $branch?->id,
            ]);

            app(RoleManager::class)->flush();
        });
    };

    /** A custom role whose grants are narrowed: `['core.contacts.view' => 'own', ...]`. */
    $this->scopedRole = fn (string $name, array $scopes): Role => $this->inTenant(
        $this->acme,
        fn (): Role => app(RoleManager::class)->create($this->owner, $name, null, array_keys($scopes), $scopes),
    );

    /** A contact named after its first name, owned by `$owner` (with their department and branch) or by nobody. */
    $this->contact = fn (string $name, ?User $owner = null, array $attributes = []): Contact => $this->inTenant(
        $this->acme,
        function () use ($name, $owner, $attributes): Contact {
            $contact = Contact::factory()->make(['first_name' => $name, 'last_name' => null, ...$attributes]);
            $contact->assignOwner($owner === null ? null : $this->membershipOf($this->acme, $owner));
            $contact->save();

            return $contact;
        },
    );

    $this->company = fn (string $name, ?User $owner = null): Company => $this->inTenant(
        $this->acme,
        function () use ($name, $owner): Company {
            $company = Company::factory()->make(['name' => $name]);
            $company->assignOwner($owner === null ? null : $this->membershipOf($this->acme, $owner));
            $company->save();

            return $company;
        },
    );

    /** The rows of a list as `$as` sees them, keyed by name. */
    $this->rows = fn (User $as, string $path = 'contacts', string $prop = 'contacts'): array => collect(
        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $as)
            ->get("http://acme.localhost/{$path}")
            ->assertOk()
            ->inertiaProps("{$prop}.data"),
    )->keyBy('name')->all();

    $this->people = fn (User $as, string $query = ''): array => array_keys(($this->rows)($as, 'contacts'.$query));
    $this->companies = fn (User $as, string $query = ''): array => array_keys(($this->rows)($as, 'companies'.$query, 'companies'));

    $this->fresh = fn (Contact|Company $record): Contact|Company => $this->inTenant($this->acme, fn () => $record->refresh());
});

describe('an Employee, who sees every contact and changes only their own', function () {
    beforeEach(function () {
        $this->mine = ($this->contact)('Mine', $this->alice);
        $this->theirs = ($this->contact)('Theirs', $this->bob);
        $this->nobodys = ($this->contact)('Unowned');

        $this->myCompany = ($this->company)('My Co', $this->alice);
        $this->theirCompany = ($this->company)('Their Co', $this->bob);
    });

    it('lists everyone\'s contacts and companies, and marks only their own as theirs to change', function () {
        $rows = ($this->rows)($this->alice);

        expect(array_keys($rows))->toBe(['Mine', 'Theirs', 'Unowned'])
            ->and(array_map(fn (array $row): bool => $row['editable'], $rows))->toBe(['Mine' => true, 'Theirs' => false, 'Unowned' => false])
            // Deleting is not part of the role at all.
            ->and(array_filter(array_column($rows, 'deletable')))->toBe([])
            ->and($rows['Theirs']['owner'])->toBe(['id' => ($this->membership)($this->bob)->id, 'name' => 'Bob Biswas'])
            ->and($rows['Unowned']['owner'])->toBeNull();

        $companies = ($this->rows)($this->alice, 'companies', 'companies');

        expect(array_map(fn (array $row): bool => $row['editable'], $companies))->toBe(['My Co' => true, 'Their Co' => false])
            ->and(array_filter(array_column($companies, 'deletable')))->toBe([]);
    });

    it('answers 404 when they change a contact that is not theirs, and changes nothing', function () {
        $this->actingAsMember($this->acme, $this->alice);

        $this->put("http://acme.localhost/contacts/{$this->theirs->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/contacts/{$this->nobodys->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/companies/{$this->theirCompany->id}", ['name' => 'Taken Co'])->assertNotFound();

        expect(($this->fresh)($this->theirs)->first_name)->toBe('Theirs')
            ->and(($this->fresh)($this->nobodys)->first_name)->toBe('Unowned')
            ->and(($this->fresh)($this->theirCompany)->name)->toBe('Their Co')
            ->and($this->auditTrail($this->acme, 'contact.updated'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'company.updated'))->toBeEmpty();
    });

    it('lets them change their own', function () {
        $this->actingAsMember($this->acme, $this->alice);

        $this->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Still mine', 'company_id' => $this->theirCompany->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/companies/{$this->myCompany->id}", ['name' => 'Still my Co'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $mine = ($this->fresh)($this->mine);

        expect($mine->first_name)->toBe('Still mine')
            // They see every company, so any of them can be chosen.
            ->and($mine->company_id)->toBe($this->theirCompany->id)
            ->and($mine->owner_id)->toBe(($this->membership)($this->alice)->id)
            ->and(($this->fresh)($this->myCompany)->name)->toBe('Still my Co');
    });

    it('does not let them delete anything, their own included', function () {
        $this->actingAsMember($this->acme, $this->alice);

        $this->delete("http://acme.localhost/contacts/{$this->mine->id}")->assertForbidden();
        $this->delete("http://acme.localhost/contacts/{$this->theirs->id}")->assertForbidden();
        $this->delete("http://acme.localhost/companies/{$this->myCompany->id}")->assertForbidden();

        expect($this->inTenant($this->acme, fn () => [Contact::query()->count(), Company::query()->count()]))->toBe([3, 2]);
    });

    it('stops being theirs when the contact is handed to someone else', function () {
        $this->inTenant($this->acme, fn () => $this->mine->assignOwner(($this->membership)($this->bob))->save());

        $this->actingAsMember($this->acme, $this->alice)
            ->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Taken back'])
            ->assertNotFound();

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->bob)
            ->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Now Bob\'s'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->fresh)($this->mine)->first_name)->toBe('Now Bob\'s');
    });
});

describe('a record added by a member', function () {
    it('becomes theirs, with their department and branch', function () {
        $sales = ($this->department)('Sales');
        $dhaka = ($this->branch)('Dhaka');
        ($this->place)($this->alice, $sales, $dhaka);

        $this->actingAsMember($this->acme, $this->alice);

        $this->post('http://acme.localhost/contacts', ['first_name' => 'Fresh'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('http://acme.localhost/companies', ['name' => 'Fresh Co'])->assertRedirect()->assertSessionHasNoErrors();

        $expected = [
            'owner_id' => ($this->membership)($this->alice)->id,
            'department_id' => $sales->id,
            'branch_id' => $dhaka->id,
        ];

        $contact = $this->inTenant($this->acme, fn () => Contact::query()->sole());
        $company = $this->inTenant($this->acme, fn () => Company::query()->sole());

        expect($contact->only(['owner_id', 'department_id', 'branch_id']))->toBe($expected)
            ->and($company->only(['owner_id', 'department_id', 'branch_id']))->toBe($expected);

        $rows = ($this->rows)($this->alice);

        expect($rows['Fresh']['owner'])->toBe(['id' => $expected['owner_id'], 'name' => 'Alice Akter'])
            ->and($rows['Fresh']['editable'])->toBeTrue();

        // It stays with the team it was made in when its owner moves on.
        ($this->place)($this->alice, ($this->department)('Support'), null);

        expect(($this->fresh)($contact)->only(['owner_id', 'department_id', 'branch_id']))->toBe($expected);
    });

    it('belongs to someone without a department or branch just the same', function () {
        $this->actingAsMember($this->acme, $this->bob)
            ->post('http://acme.localhost/contacts', ['first_name' => 'Fresh'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect($this->inTenant($this->acme, fn () => Contact::query()->sole())->only(['owner_id', 'department_id', 'branch_id']))->toBe([
            'owner_id' => ($this->membership)($this->bob)->id,
            'department_id' => null,
            'branch_id' => null,
        ]);
    });
});

describe('handing a record to another member', function () {
    beforeEach(function () {
        $this->sales = ($this->department)('Sales');
        $this->support = ($this->department)('Support');
        $this->dhaka = ($this->branch)('Dhaka');

        ($this->place)($this->bob, $this->sales, $this->dhaka);
        ($this->place)($this->alice, $this->support, null);

        $this->ownership = fn (Contact|Company $record): array => ($this->fresh)($record)->only(['owner_id', 'department_id', 'branch_id']);
    });

    it('is open to someone who manages every contact', function () {
        $bob = ($this->membership)($this->bob)->id;
        $alice = ($this->membership)($this->alice)->id;

        $this->actingAsMember($this->acme, $this->manager);

        // On the way in ...
        $this->post('http://acme.localhost/contacts', ['first_name' => 'Handed', 'owner_id' => $bob])->assertRedirect()->assertSessionHasNoErrors();

        $contact = $this->inTenant($this->acme, fn () => Contact::query()->sole());

        expect(($this->ownership)($contact))->toBe(['owner_id' => $bob, 'department_id' => $this->sales->id, 'branch_id' => $this->dhaka->id]);

        // ... later, taking the new owner's department and branch ...
        $this->put("http://acme.localhost/contacts/{$contact->id}", ['first_name' => 'Handed', 'owner_id' => $alice])->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->ownership)($contact))->toBe(['owner_id' => $alice, 'department_id' => $this->support->id, 'branch_id' => null]);

        $entry = $this->auditTrail($this->acme, 'contact.updated')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->properties)->toEqual([
                'old' => ['owner_id' => $bob, 'department_id' => $this->sales->id, 'branch_id' => $this->dhaka->id],
                'attributes' => ['owner_id' => $alice, 'department_id' => $this->support->id, 'branch_id' => null],
            ]);

        // ... and to nobody.
        $this->put("http://acme.localhost/contacts/{$contact->id}", ['first_name' => 'Handed', 'owner_id' => null])->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->ownership)($contact))->toBe(['owner_id' => null, 'department_id' => null, 'branch_id' => null]);
    });

    it('works the same for a company', function () {
        $bob = ($this->membership)($this->bob)->id;

        $this->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/companies', ['name' => 'Handed Co', 'owner_id' => $bob])->assertRedirect()->assertSessionHasNoErrors();

        $company = $this->inTenant($this->acme, fn () => Company::query()->sole());

        expect(($this->ownership)($company))->toBe(['owner_id' => $bob, 'department_id' => $this->sales->id, 'branch_id' => $this->dhaka->id]);

        $this->put("http://acme.localhost/companies/{$company->id}", ['name' => 'Handed Co', 'owner_id' => null])->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->ownership)($company))->toBe(['owner_id' => null, 'department_id' => null, 'branch_id' => null]);
    });

    it('leaves the owner alone when none is sent', function () {
        $contact = ($this->contact)('Kept', $this->bob);
        $company = ($this->company)('Kept Co', $this->bob);

        $this->actingAsMember($this->acme, $this->manager);

        $this->put("http://acme.localhost/contacts/{$contact->id}", ['first_name' => 'Kept on'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/companies/{$company->id}", ['name' => 'Kept on Co'])->assertRedirect()->assertSessionHasNoErrors();

        $expected = ['owner_id' => ($this->membership)($this->bob)->id, 'department_id' => $this->sales->id, 'branch_id' => $this->dhaka->id];

        expect(($this->ownership)($contact))->toBe($expected)
            ->and(($this->ownership)($company))->toBe($expected)
            ->and(($this->fresh)($contact)->first_name)->toBe('Kept on');
    });

    it('is ignored from someone who manages only their own', function () {
        $bob = ($this->membership)($this->bob)->id;
        $alice = ($this->membership)($this->alice)->id;
        $mine = ($this->contact)('Mine', $this->alice);
        $myCompany = ($this->company)('My Co', $this->alice);

        $this->actingAsMember($this->acme, $this->alice);

        $this->post('http://acme.localhost/contacts', ['first_name' => 'Fresh', 'owner_id' => $bob])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('http://acme.localhost/companies', ['name' => 'Fresh Co', 'owner_id' => $bob])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/contacts/{$mine->id}", ['first_name' => 'Mine', 'owner_id' => $bob])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/contacts/{$mine->id}", ['first_name' => 'Mine', 'owner_id' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/companies/{$myCompany->id}", ['name' => 'My Co', 'owner_id' => $bob])->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant($this->acme, function () use ($alice): void {
            expect(Contact::query()->pluck('owner_id', 'first_name')->all())->toEqual(['Fresh' => $alice, 'Mine' => $alice])
                ->and(Company::query()->pluck('owner_id', 'name')->all())->toEqual(['Fresh Co' => $alice, 'My Co' => $alice]);
        });

        // And the page offers them nobody to hand a record to.
        $this->get('http://acme.localhost/contacts')->assertInertia(fn (AssertableInertia $page) => $page->where('owners', []));
        $this->get('http://acme.localhost/companies')->assertInertia(fn (AssertableInertia $page) => $page->where('owners', []));
    });

    it('is ignored from someone who manages their department\'s', function () {
        $lead = $this->addMember($this->acme, ($this->scopedRole)('Team leads', [
            'core.contacts.view' => 'all',
            'core.contacts.manage' => 'department',
        ]));
        ($this->place)($lead, $this->sales, null);

        $bobs = ($this->contact)('Bobs', $this->bob);

        $this->actingAsMember($this->acme, $lead)
            ->put("http://acme.localhost/contacts/{$bobs->id}", ['first_name' => 'Bobs', 'phone' => '+8801711000009', 'owner_id' => ($this->membership)($this->alice)->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->fresh)($bobs)->only(['phone', 'owner_id']))->toBe(['phone' => '+8801711000009', 'owner_id' => ($this->membership)($this->bob)->id]);

        $this->get('http://acme.localhost/contacts')->assertInertia(fn (AssertableInertia $page) => $page->where('owners', []));
    });

    it('lets a record whose owner has since been suspended be saved with the owner left as is', function () {
        $contact = ($this->contact)('Kept', $this->bob);
        $company = ($this->company)('Kept Co', $this->bob);
        $other = ($this->contact)('Other', $this->alice);
        $bob = ($this->membership)($this->bob);

        $this->inTenant($this->acme, function () use ($bob): void {
            $bob->update(['status' => MembershipStatus::Suspended]);

            app(RoleManager::class)->flush();
        });

        $this->actingAsMember($this->acme, $this->manager);

        // The form sends back the owner it was given: nothing is being handed over.
        $this->put("http://acme.localhost/contacts/{$contact->id}", ['first_name' => 'Kept on', 'owner_id' => $bob->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/companies/{$company->id}", ['name' => 'Kept on Co', 'owner_id' => $bob->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(($this->fresh)($contact)->only(['first_name', 'owner_id']))->toBe(['first_name' => 'Kept on', 'owner_id' => $bob->id])
            ->and(($this->fresh)($company)->only(['name', 'owner_id']))->toBe(['name' => 'Kept on Co', 'owner_id' => $bob->id]);

        // Handing them another record is still refused.
        $this->put("http://acme.localhost/contacts/{$other->id}", ['first_name' => 'Other', 'owner_id' => $bob->id])->assertNotFound();

        expect(($this->fresh)($other)->owner_id)->toBe(($this->membership)($this->alice)->id);
    });

    it('offers the active members of this workspace, and accepts no one else', function () {
        $globex = $this->createTenant('globex');
        $outsider = $this->membershipOf($globex, $this->ownerOf($globex));
        $suspended = $this->addMember($this->acme, Role::EMPLOYEE, User::factory()->create(['name' => 'Sam Suspended']), MembershipStatus::Suspended);
        $contact = ($this->contact)('Kept', $this->bob);

        $this->actingAsMember($this->acme, $this->manager)
            ->get('http://acme.localhost/contacts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('owners', fn ($owners): bool => collect($owners)->pluck('id')->sort()->values()->all() === collect([
                    ($this->membership)($this->owner)->id,
                    ($this->membership)($this->manager)->id,
                    ($this->membership)($this->alice)->id,
                    ($this->membership)($this->bob)->id,
                ])->sort()->values()->all()));

        foreach ([$outsider->id, ($this->membership)($suspended)->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            $this->post('http://acme.localhost/contacts', ['first_name' => 'Fresh', 'owner_id' => $id])->assertNotFound();
            $this->post('http://acme.localhost/companies', ['name' => 'Fresh Co', 'owner_id' => $id])->assertNotFound();
            $this->put("http://acme.localhost/contacts/{$contact->id}", ['first_name' => 'Changed', 'owner_id' => $id])->assertNotFound();
        }

        $this->inTenant($this->acme, function () use ($contact): void {
            expect(Contact::query()->pluck('first_name')->all())->toBe(['Kept'])
                ->and(Company::query()->count())->toBe(0)
                ->and($contact->refresh()->owner_id)->toBe(($this->membership)($this->bob)->id);
        });
    });
});

describe('a view permission narrowed to own records', function () {
    beforeEach(function () {
        $this->carol = $this->addMember(
            $this->acme,
            ($this->scopedRole)('Own contacts', ['core.contacts.view' => 'own', 'core.contacts.manage' => 'own']),
            User::factory()->create(['name' => 'Carol Chowdhury']),
        );

        $this->mine = ($this->contact)('Mine', $this->carol);
        $this->theirs = ($this->contact)('Theirs', $this->bob);
        $this->nobodys = ($this->contact)('Unowned');

        $this->myCompany = ($this->company)('My Co', $this->carol);
        $this->theirCompany = ($this->company)('Their Co', $this->bob);
    });

    it('lists only their own contacts and companies', function () {
        expect(($this->people)($this->carol))->toBe(['Mine'])
            ->and(($this->companies)($this->carol))->toBe(['My Co'])
            // Search does not reach past the scope either.
            ->and(($this->people)($this->carol, '?search=Theirs'))->toBe([])
            ->and(($this->people)($this->carol, '?search=e'))->toBe(['Mine'])
            ->and(($this->companies)($this->carol, '?search=Their'))->toBe([])
            // Everyone else still sees the lot.
            ->and(($this->people)($this->alice))->toBe(['Mine', 'Theirs', 'Unowned'])
            ->and(($this->companies)($this->manager))->toBe(['My Co', 'Their Co']);

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->carol)
            ->get('http://acme.localhost/contacts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contacts.total', 1)
                ->where('contacts.data.0.editable', true)
                ->where('canCreate', true)
                ->where('owners', []));
    });

    it('offers only the companies they can see to attach a contact to', function () {
        $this->actingAsMember($this->acme, $this->carol)
            ->get('http://acme.localhost/contacts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies', [['id' => $this->myCompany->id, 'name' => 'My Co']]));

        // Someone who sees every company is offered every company.
        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->alice)
            ->get('http://acme.localhost/contacts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies', [
                    ['id' => $this->myCompany->id, 'name' => 'My Co'],
                    ['id' => $this->theirCompany->id, 'name' => 'Their Co'],
                ]));
    });

    it('answers 404 when they attach a contact to a company they cannot see', function () {
        $this->actingAsMember($this->acme, $this->carol);

        $this->post('http://acme.localhost/contacts', ['first_name' => 'Fresh', 'company_id' => $this->theirCompany->id])->assertNotFound();
        $this->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Mine', 'company_id' => $this->theirCompany->id])->assertNotFound();

        expect($this->inTenant($this->acme, fn () => Contact::query()->where('first_name', 'Fresh')->exists()))->toBeFalse()
            ->and(($this->fresh)($this->mine)->company_id)->toBeNull();

        $this->post('http://acme.localhost/contacts', ['first_name' => 'Fresh', 'company_id' => $this->myCompany->id])->assertRedirect()->assertSessionHasNoErrors();

        expect($this->inTenant($this->acme, fn () => Contact::query()->where('first_name', 'Fresh')->sole())->company_id)->toBe($this->myCompany->id);
    });

    it('lets them save their own contact that someone else put in a company they cannot see', function () {
        // A manager attached Carol's contact to Bob's company.
        $this->inTenant($this->acme, fn () => $this->mine->update(['company_id' => $this->theirCompany->id]));

        $this->actingAsMember($this->acme, $this->carol);

        // Saving it with the company left as it is does not ask for anything new.
        $this->put("http://acme.localhost/contacts/{$this->mine->id}", [
            'first_name' => 'Mine',
            'phone' => '+8801711000007',
            'company_id' => $this->theirCompany->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->fresh)($this->mine)->only(['phone', 'company_id']))->toBe([
            'phone' => '+8801711000007',
            'company_id' => $this->theirCompany->id,
        ]);

        // Moving it to another company they cannot see is still refused ...
        $other = ($this->company)('Other Co', $this->bob);

        $this->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Mine', 'company_id' => $other->id])->assertNotFound();

        expect(($this->fresh)($this->mine)->company_id)->toBe($this->theirCompany->id);

        // ... and taking it out of the company is theirs to do.
        $this->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Mine', 'company_id' => null])->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->fresh)($this->mine)->company_id)->toBeNull();
    });

    it('does not filter by, or name, a company they cannot see', function () {
        ($this->contact)('In their company', $this->bob, ['company_id' => $this->theirCompany->id]);
        ($this->contact)('In my company', $this->carol, ['company_id' => $this->myCompany->id]);

        $this->actingAsMember($this->acme, $this->carol)
            ->get("http://acme.localhost/contacts?company={$this->theirCompany->id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->where('filters.company', null);

                $props = $page->toArray()['props'];

                // The list is the one they would get without the filter: their own, wherever they work.
                expect(array_column($props['contacts']['data'], 'name'))->toBe(['In my company', 'Mine'])
                    ->and(json_encode($props))->not->toContain('Their Co', 'In their company');
            });

        $this->get("http://acme.localhost/contacts?company={$this->myCompany->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.company', ['id' => $this->myCompany->id, 'name' => 'My Co'])
                ->where('contacts.total', 1)
                ->where('contacts.data.0.name', 'In my company'));

        // Someone who sees every contact gets the filter.
        expect(($this->people)($this->alice, "?company={$this->theirCompany->id}"))->toBe(['In their company']);
    });

    it('counts only the people they can see in a company', function () {
        ($this->contact)('Mine too', $this->carol, ['company_id' => $this->myCompany->id]);
        ($this->contact)('Bob\'s there', $this->bob, ['company_id' => $this->myCompany->id]);
        ($this->contact)('Nobody\'s there', null, ['company_id' => $this->myCompany->id]);

        expect(($this->rows)($this->carol, 'companies', 'companies')['My Co']['contacts'])->toBe(1)
            // What the count links to shows as many.
            ->and(($this->people)($this->carol, "?company={$this->myCompany->id}"))->toBe(['Mine too'])
            ->and(($this->rows)($this->alice, 'companies', 'companies')['My Co']['contacts'])->toBe(3)
            ->and(($this->people)($this->alice, "?company={$this->myCompany->id}"))->toHaveCount(3);
    });

    it('answers 404 for a record outside their scope, like one that does not exist', function () {
        $this->actingAsMember($this->acme, $this->carol);

        $this->put("http://acme.localhost/contacts/{$this->theirs->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/companies/{$this->theirCompany->id}", ['name' => 'Taken Co'])->assertNotFound();
        $this->put("http://acme.localhost/contacts/{$this->mine->id}", ['first_name' => 'Still mine'])->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->fresh)($this->theirs)->first_name)->toBe('Theirs')
            ->and(($this->fresh)($this->mine)->first_name)->toBe('Still mine');
    });
});

describe('a delete permission narrowed to own records', function () {
    it('deletes their own records and answers 404 for anyone else\'s', function () {
        $dave = $this->addMember($this->acme, ($this->scopedRole)('Tidy', [
            'core.contacts.view' => 'all',
            'core.contacts.delete' => 'own',
        ]));

        $mine = ($this->contact)('Mine', $dave);
        $theirs = ($this->contact)('Theirs', $this->bob);
        $myCompany = ($this->company)('My Co', $dave);
        $theirCompany = ($this->company)('Their Co', $this->bob);

        $rows = ($this->rows)($dave);

        expect(array_map(fn (array $row): bool => $row['deletable'], $rows))->toBe(['Mine' => true, 'Theirs' => false])
            ->and(array_filter(array_column($rows, 'editable')))->toBe([]);

        $this->delete("http://acme.localhost/contacts/{$theirs->id}")->assertNotFound();
        $this->delete("http://acme.localhost/companies/{$theirCompany->id}")->assertNotFound();
        $this->delete("http://acme.localhost/contacts/{$mine->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->delete("http://acme.localhost/companies/{$myCompany->id}")->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant($this->acme, function () use ($theirs, $theirCompany): void {
            expect(Contact::query()->pluck('id')->all())->toBe([$theirs->id])
                ->and(Company::query()->pluck('id')->all())->toBe([$theirCompany->id]);
        });
    });
});

describe('a permission narrowed to the department', function () {
    beforeEach(function () {
        $this->sales = ($this->department)('Sales');
        $this->support = ($this->department)('Support');

        $role = ($this->scopedRole)('Department desk', [
            'core.contacts.view' => 'department',
            'core.contacts.manage' => 'department',
            'core.contacts.delete' => 'department',
        ]);

        $this->dana = $this->addMember($this->acme, $role, User::factory()->create(['name' => 'Dana Das']));
        $this->gus = $this->addMember($this->acme, $role, User::factory()->create(['name' => 'Gus Gomes']));

        ($this->place)($this->dana, $this->sales);
        ($this->place)($this->alice, $this->sales);
        ($this->place)($this->bob, $this->support);
        // Gus has no department.

        $this->danas = ($this->contact)('Danas', $this->dana);
        $this->salesContact = ($this->contact)('Sales colleague\'s', $this->alice);
        $this->supportContact = ($this->contact)('Support\'s', $this->bob);
        $this->nobodys = ($this->contact)('Unowned');
        $this->guss = ($this->contact)('Guss', $this->gus);
        $this->managers = ($this->contact)('Managers', $this->manager);

        $this->salesCompany = ($this->company)('Sales Co', $this->alice);
        $this->supportCompany = ($this->company)('Support Co', $this->bob);
    });

    it('lists their own records and those of their department', function () {
        expect(($this->people)($this->dana))->toBe(['Danas', 'Sales colleague\'s'])
            ->and(($this->companies)($this->dana))->toBe(['Sales Co'])
            ->and(($this->people)($this->dana, '?search=Support'))->toBe([]);

        $this->get('http://acme.localhost/contacts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies', [['id' => $this->salesCompany->id, 'name' => 'Sales Co']])
                ->where('owners', []));
    });

    it('gives someone without a department no more than their own', function () {
        // Neither the other department-less records, nor the ones that belong to nobody.
        expect(($this->people)($this->gus))->toBe(['Guss'])
            ->and(($this->companies)($this->gus))->toBe([]);

        $this->put("http://acme.localhost/contacts/{$this->nobodys->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/contacts/{$this->managers->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/contacts/{$this->guss->id}", ['first_name' => 'Still Guss'])->assertRedirect()->assertSessionHasNoErrors();
    });

    it('lets them change and delete what their department owns, and nothing else', function () {
        $rows = ($this->rows)($this->dana);

        expect(array_map(fn (array $row): array => [$row['editable'], $row['deletable']], $rows))->toBe([
            'Danas' => [true, true],
            'Sales colleague\'s' => [true, true],
        ]);

        $this->put("http://acme.localhost/contacts/{$this->salesContact->id}", ['first_name' => 'Helped'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/companies/{$this->salesCompany->id}", ['name' => 'Helped Co'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/contacts/{$this->supportContact->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/contacts/{$this->nobodys->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->put("http://acme.localhost/companies/{$this->supportCompany->id}", ['name' => 'Taken Co'])->assertNotFound();
        $this->delete("http://acme.localhost/contacts/{$this->supportContact->id}")->assertNotFound();
        $this->delete("http://acme.localhost/companies/{$this->supportCompany->id}")->assertNotFound();
        $this->delete("http://acme.localhost/contacts/{$this->danas->id}")->assertRedirect()->assertSessionHasNoErrors();

        $helped = ($this->fresh)($this->salesContact);

        expect($helped->first_name)->toBe('Helped')
            // Helping a colleague does not take their contact from them.
            ->and($helped->owner_id)->toBe(($this->membership)($this->alice)->id)
            ->and(($this->fresh)($this->salesCompany)->name)->toBe('Helped Co')
            ->and(($this->fresh)($this->supportContact)->first_name)->toBe('Support\'s')
            ->and($this->inTenant($this->acme, fn () => Contact::query()->whereKey($this->danas->id)->exists()))->toBeFalse();
    });

    it('goes by the department a record was made in, not where its owner is now', function () {
        // Alice moves from Sales to Support; what she owned stays with Sales.
        ($this->place)($this->alice, $this->support);
        $later = ($this->contact)('Made in Support', $this->alice);

        expect(($this->people)($this->dana))->toBe(['Danas', 'Sales colleague\'s'])
            ->and(($this->fresh)($later)->department_id)->toBe($this->support->id);

        // And Dana, moved to Support, sees Support's instead - plus her own, wherever they were made.
        ($this->place)($this->dana, $this->support);

        expect(($this->people)($this->dana))->toBe(['Danas', 'Made in Support', 'Support\'s']);
    });
});

describe('a permission narrowed to the branch', function () {
    it('lists their own records, their branch\'s and their department\'s', function () {
        $sales = ($this->department)('Sales');
        $support = ($this->department)('Support');
        $dhaka = ($this->branch)('Dhaka');
        $chattogram = ($this->branch)('Chattogram');

        $hana = $this->addMember($this->acme, ($this->scopedRole)('Branch desk', [
            'core.contacts.view' => 'branch',
            'core.contacts.manage' => 'own',
        ]));
        $kim = $this->addMember($this->acme, Role::EMPLOYEE);

        ($this->place)($hana, $sales, $dhaka);
        ($this->place)($this->alice, $support, $dhaka);
        ($this->place)($this->bob, $sales, $chattogram);
        ($this->place)($kim, $support, $chattogram);

        ($this->contact)('Hanas', $hana);
        ($this->contact)('Same branch', $this->alice);
        ($this->contact)('Same department', $this->bob);
        ($this->contact)('Neither', $kim);
        ($this->contact)('Unowned');

        $rows = ($this->rows)($hana);

        expect(array_keys($rows))->toBe(['Hanas', 'Same branch', 'Same department'])
            // Seeing the branch's records is not managing them.
            ->and(array_map(fn (array $row): bool => $row['editable'], $rows))->toBe(['Hanas' => true, 'Same branch' => false, 'Same department' => false]);
    });
});
