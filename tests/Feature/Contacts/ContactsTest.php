<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Contacts\Events\ContactCreated;
use App\Core\Contacts\Events\ContactDeleted;
use App\Core\Contacts\Events\ContactUpdated;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

/*
 * The people of the shared contact directory, over HTTP. Records are made
 * before anyone signs in: a record made by a signed-in member becomes theirs.
 * What a narrowed permission scope does to all of this is in ContactScopeTest.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->manager = $this->addMember($this->acme, Role::MANAGER);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);

    /** A contact in a workspace (acme unless named), owned by `$owner` or by nobody. */
    $this->contact = function (array $attributes = [], ?User $owner = null, ?Tenant $tenant = null): Contact {
        $tenant ??= $this->acme;

        return $this->inTenant($tenant, function () use ($attributes, $owner, $tenant): Contact {
            $contact = Contact::factory()->make($attributes);

            if ($owner !== null) {
                $contact->assignOwner($this->membershipOf($tenant, $owner));
            }

            $contact->save();

            return $contact;
        });
    };

    $this->company = fn (array $attributes = [], ?Tenant $tenant = null): Company => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): Company => Company::factory()->create($attributes),
    );

    /** Names on the people list as `$as` sees it, in the order shown. */
    $this->names = fn (string $query = '', ?User $as = null): array => array_column(
        $this->actingAsMember($this->acme, $as ?? $this->admin)
            ->get('http://acme.localhost/contacts'.$query)
            ->assertOk()
            ->inertiaProps('contacts.data'),
        'name',
    );

    /** Every contact of acme that has not been deleted. */
    $this->stored = fn () => $this->inTenant($this->acme, fn () => Contact::query()->orderBy('id')->get());

    /** Everything the form sends. */
    $this->input = fn (array $overrides = []): array => [
        'first_name' => 'Imran',
        'last_name' => 'Hossain',
        'email' => 'imran@northwind.test',
        'phone' => '+8801711000001',
        'job_title' => 'Purchasing lead',
        'company_id' => null,
        'address' => 'House 12, Road 5, Dhanmondi',
        'city' => 'Dhaka',
        'country' => 'BD',
        'notes' => 'Prefers a call before noon.',
        ...$overrides,
    ];
});

describe('the people list', function () {
    it('lists the contacts of this workspace, and only them, with everything the page shows', function () {
        $company = ($this->company)(['name' => 'Northwind Traders']);
        $contact = ($this->contact)(($this->input)(['company_id' => $company->id]), $this->manager);
        $foreign = ($this->contact)(['first_name' => 'Someone', 'last_name' => 'Elsewhere'], tenant: $this->globex);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/contacts')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company, $contact, $foreign): void {
                $page->component('Core/Pages/Contacts/Index')
                    ->where('contacts.total', 1)
                    ->where('contacts.per_page', 25)
                    ->where('filters', ['search' => '', 'company' => null, 'sort' => 'name', 'direction' => 'asc'])
                    ->where('companies', [['id' => $company->id, 'name' => 'Northwind Traders']])
                    ->where('canCreate', true);

                $props = $page->toArray()['props'];

                expect($props['contacts']['data'][0])->toEqual([
                    'id' => $contact->id,
                    'name' => 'Imran Hossain',
                    'firstName' => 'Imran',
                    'lastName' => 'Hossain',
                    'email' => 'imran@northwind.test',
                    'phone' => '+8801711000001',
                    'jobTitle' => 'Purchasing lead',
                    'address' => 'House 12, Road 5, Dhanmondi',
                    'city' => 'Dhaka',
                    'country' => 'BD',
                    'notes' => 'Prefers a call before noon.',
                    'company' => ['id' => $company->id, 'name' => 'Northwind Traders'],
                    'owner' => ['id' => $this->membershipOf($this->acme, $this->manager)->id, 'name' => $this->manager->name],
                    'editable' => true,
                    'deletable' => true,
                    'createdAt' => $contact->created_at->toIso8601String(),
                    // Its typed addresses (ADR 0039), for the details dialog.
                    'addresses' => [],
                ])
                    // The owner is named by membership, never by account id; nothing of another workspace leaks.
                    ->and(json_encode($props['contacts']))->not->toContain($this->manager->id, $foreign->id, 'Elsewhere', 'tenant_id')
                    // Whoever manages every contact may hand one to any active member.
                    ->and(array_column($props['owners'], 'id'))->toEqualCanonicalizing([
                        $this->membershipOf($this->acme, $this->owner)->id,
                        $this->membershipOf($this->acme, $this->admin)->id,
                        $this->membershipOf($this->acme, $this->manager)->id,
                        $this->membershipOf($this->acme, $this->employee)->id,
                    ])
                    ->and(array_column($props['owners'], 'name'))->toBe(collect($props['owners'])->pluck('name')->sort()->values()->all());
            });
    });

    it('shows a contact without a company or an owner', function () {
        ($this->contact)(['first_name' => 'Solo', 'last_name' => null, 'email' => null, 'phone' => null]);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/contacts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contacts.data.0.name', 'Solo')
                ->where('contacts.data.0.lastName', null)
                ->where('contacts.data.0.company', null)
                ->where('contacts.data.0.owner', null));
    });

    it('searches by first name, last name, full name, email and phone', function () {
        ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Hossain', 'email' => 'imran@northwind.test', 'phone' => '+8801711000001']);
        ($this->contact)(['first_name' => 'Nadia', 'last_name' => 'Islam', 'email' => 'nadia@fabrikam.test', 'phone' => '+8801911222333']);
        ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Elsewhere', 'email' => 'imran@globex.test'], tenant: $this->globex);

        $find = fn (string $term): array => ($this->names)('?search='.urlencode($term));

        expect($find('imran'))->toBe(['Imran Hossain'])
            ->and($find('ISLAM'))->toBe(['Nadia Islam'])
            ->and($find('fabrikam.test'))->toBe(['Nadia Islam'])
            ->and($find('222333'))->toBe(['Nadia Islam'])
            ->and($find('+88017'))->toBe(['Imran Hossain'])
            // The name as the list shows it.
            ->and($find('Imran Hossain'))->toBe(['Imran Hossain'])
            ->and($find('nadia isl'))->toBe(['Nadia Islam'])
            ->and($find('globex'))->toBe([])
            ->and($find('Elsewhere'))->toBe([])
            // Blank is no search at all.
            ->and($find('   '))->toBe(['Imran Hossain', 'Nadia Islam']);

        $this->get('http://acme.localhost/contacts?search='.urlencode('  nadia '))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.search', 'nadia'));
    });

    it('searches for what was typed, wildcard characters included', function () {
        ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Hossain', 'email' => 'imran@northwind.test', 'phone' => '+8801711000001']);
        ($this->contact)(['first_name' => 'Fifty%', 'last_name' => 'Off_Sale', 'email' => null, 'phone' => null]);

        $find = fn (string $term): array => ($this->names)('?search='.urlencode($term));

        // On their own these would match everyone if they were passed through as wildcards.
        expect($find('%'))->toBe(['Fifty% Off_Sale'])
            ->and($find('_'))->toBe(['Fifty% Off_Sale'])
            ->and($find('Off_S'))->toBe(['Fifty% Off_Sale'])
            ->and($find('Imra_'))->toBe([])
            ->and($find('I%n'))->toBe([])
            ->and($find('\\'))->toBe([]);
    });

    it('rejects a malformed search or company filter instead of failing', function () {
        $this->actingAsMember($this->acme, $this->admin);

        $this->get('http://acme.localhost/contacts?search[]=a')->assertSessionHasErrors('search');
        $this->get('http://acme.localhost/contacts?search='.str_repeat('a', 101))->assertSessionHasErrors('search');
        $this->get('http://acme.localhost/contacts?company=abc')->assertSessionHasErrors('company');
        $this->get('http://acme.localhost/contacts?company[]=abc')->assertSessionHasErrors('company');
    });

    it('filters by company', function () {
        $northwind = ($this->company)(['name' => 'Northwind Traders']);
        $fabrikam = ($this->company)(['name' => 'Fabrikam']);

        ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Hossain', 'company_id' => $northwind->id]);
        ($this->contact)(['first_name' => 'Nadia', 'last_name' => 'Islam', 'company_id' => $northwind->id]);
        ($this->contact)(['first_name' => 'Rafiq', 'last_name' => 'Ahmed', 'company_id' => $fabrikam->id]);
        ($this->contact)(['first_name' => 'Solo', 'last_name' => 'Trader']);

        expect(($this->names)("?company={$northwind->id}"))->toBe(['Imran Hossain', 'Nadia Islam'])
            ->and(($this->names)("?company={$fabrikam->id}"))->toBe(['Rafiq Ahmed'])
            ->and(($this->names)("?company={$northwind->id}&search=nadia"))->toBe(['Nadia Islam'])
            ->and(($this->names)("?company={$fabrikam->id}&search=nadia"))->toBe([]);

        $this->get("http://acme.localhost/contacts?company={$northwind->id}&search=nadia")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters', ['search' => 'nadia', 'company' => ['id' => $northwind->id, 'name' => 'Northwind Traders'], 'sort' => 'name', 'direction' => 'asc']));
    });

    it('does not filter by a company it cannot find here, and does not name it', function () {
        $theirs = ($this->company)(['name' => 'Globex Holdings'], $this->globex);
        $deleted = ($this->company)(['name' => 'Gone Ltd']);
        $this->inTenant($this->acme, fn () => $deleted->delete());

        ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Hossain']);
        ($this->contact)(['first_name' => 'Their', 'last_name' => 'Person', 'company_id' => $theirs->id], tenant: $this->globex);

        foreach ([$theirs->id, $deleted->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            $this->actingAsMember($this->acme, $this->admin)
                ->get("http://acme.localhost/contacts?company={$id}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('filters.company', null)
                    ->where('contacts.total', 1)
                    ->where('contacts.data.0.name', 'Imran Hossain'));
        }
    });

    it('orders by first name, then last name, then age, 25 to a page', function () {
        ($this->contact)(['first_name' => 'Zara', 'last_name' => 'Ahmed']);
        ($this->contact)(['first_name' => 'Adam', 'last_name' => 'Zaman']);
        ($this->contact)(['first_name' => 'Adam', 'last_name' => 'Akter']);

        foreach (range(1, 26) as $number) {
            ($this->contact)(['first_name' => 'Karim', 'last_name' => sprintf('L%02d', $number)]);
        }

        // Two people with the same name stay in the order they were added.
        $elder = ($this->contact)(['first_name' => 'Adam', 'last_name' => 'Akter']);
        $younger = ($this->contact)(['first_name' => 'Adam', 'last_name' => 'Akter']);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/contacts')
            ->assertInertia(function (AssertableInertia $page) use ($elder, $younger): void {
                $page->where('contacts.total', 31)
                    ->where('contacts.per_page', 25)
                    ->where('contacts.last_page', 2)
                    ->has('contacts.data', 25);

                $rows = $page->toArray()['props']['contacts']['data'];

                expect(array_column($rows, 'name'))->toBe([
                    'Adam Akter', 'Adam Akter', 'Adam Akter', 'Adam Zaman',
                    ...array_map(fn (int $number): string => sprintf('Karim L%02d', $number), range(1, 21)),
                ])
                    ->and(array_slice(array_column($rows, 'id'), 1, 2))->toBe([$elder->id, $younger->id]);
            });

        expect(($this->names)('?page=2'))->toBe(['Karim L22', 'Karim L23', 'Karim L24', 'Karim L25', 'Karim L26', 'Zara Ahmed']);

        // Page links keep the filters.
        $this->get('http://acme.localhost/contacts?search=karim')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contacts.total', 26)
                ->where('contacts.next_page_url', fn (string $url): bool => str_contains($url, 'search=karim') && str_contains($url, 'page=2')));
    });
});

describe('sorting the people list', function () {
    beforeEach(function () {
        $this->actingAsMember($this->acme, $this->admin);
        $northwind = ($this->company)(['name' => 'Northwind Traders']);
        $acorn = ($this->company)(['name' => 'Acorn Ltd']);

        ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Hossain', 'company_id' => $northwind->id, 'email' => 'imran@example.test', 'city' => 'Sylhet']);
        $this->travel(1)->minute();
        ($this->contact)(['first_name' => 'Nadia', 'last_name' => 'Islam', 'company_id' => $acorn->id, 'email' => 'nadia@example.test', 'city' => 'Dhaka']);
        $this->travel(1)->minute();
        ($this->contact)(['first_name' => 'Arif', 'last_name' => 'Khan', 'company_id' => null, 'email' => null, 'city' => null]);
    });

    it('sorts by each column the list offers, in both directions, keeping blanks last', function (string $query, array $expected) {
        expect(($this->names)($query))->toBe($expected);
    })->with([
        'name, default' => ['', ['Arif Khan', 'Imran Hossain', 'Nadia Islam']],
        'name, descending' => ['?sort=name&direction=desc', ['Nadia Islam', 'Imran Hossain', 'Arif Khan']],
        'company' => ['?sort=company', ['Nadia Islam', 'Imran Hossain', 'Arif Khan']],
        'company, descending' => ['?sort=company&direction=desc', ['Imran Hossain', 'Nadia Islam', 'Arif Khan']],
        'email' => ['?sort=email', ['Imran Hossain', 'Nadia Islam', 'Arif Khan']],
        'city' => ['?sort=city', ['Nadia Islam', 'Imran Hossain', 'Arif Khan']],
        'newest first' => ['?sort=created&direction=desc', ['Arif Khan', 'Nadia Islam', 'Imran Hossain']],
    ]);

    it('says which order it applied, and keeps it in the page links', function () {
        $this->get('http://acme.localhost/contacts?sort=city&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'city')
                ->where('filters.direction', 'desc'));
    });

    it('refuses an order it does not offer', function (string $query, string $field) {
        $this->get('http://acme.localhost/contacts'.$query)->assertSessionHasErrors($field);
    })->with([
        ['?sort=password', 'sort'],
        ['?sort=name&direction=sideways', 'direction'],
    ]);
});
describe('adding a contact', function () {
    it('stores every field, lower-cases the email, and makes the person who added it the owner', function () {
        Event::fake([ContactCreated::class]);

        $company = ($this->company)(['name' => 'Northwind Traders']);

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', ($this->input)([
                'email' => 'Imran.Hossain@Northwind.TEST',
                'company_id' => $company->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Contact "Imran Hossain" added.');

        $contact = ($this->stored)()->sole();

        expect($contact->only(['first_name', 'last_name', 'email', 'phone', 'job_title', 'company_id', 'address', 'city', 'country', 'notes']))->toBe([
            'first_name' => 'Imran',
            'last_name' => 'Hossain',
            'email' => 'imran.hossain@northwind.test',
            'phone' => '+8801711000001',
            'job_title' => 'Purchasing lead',
            'company_id' => $company->id,
            'address' => 'House 12, Road 5, Dhanmondi',
            'city' => 'Dhaka',
            'country' => 'BD',
            'notes' => 'Prefers a call before noon.',
        ])
            ->and($contact->tenant_id)->toBe($this->acme->id)
            ->and($contact->owner_id)->toBe($this->membershipOf($this->acme, $this->manager)->id);

        $entry = $this->auditTrail($this->acme, 'contact.created')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->subject_type)->toBe('contact')
            ->and($entry->subject_id)->toBe($contact->id)
            ->and($entry->subject_label)->toBe('Imran Hossain')
            ->and($entry->properties['attributes'])->toMatchArray([
                'first_name' => 'Imran',
                'email' => 'imran.hossain@northwind.test',
                'company_id' => $company->id,
                'owner_id' => $contact->owner_id,
            ]);

        Event::assertDispatchedTimes(ContactCreated::class, 1);
        Event::assertDispatched(ContactCreated::class, fn (ContactCreated $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'contact_id' => $contact->id,
                'company_id' => $company->id,
                'name' => 'Imran Hossain',
                'email' => 'imran.hossain@northwind.test',
                'phone' => '+8801711000001',
            ]);
    });

    it('needs nothing but a first name', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', ['first_name' => 'Solo'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $contact = ($this->stored)()->sole();

        expect($contact->fullName())->toBe('Solo')
            ->and($contact->only(['last_name', 'email', 'phone', 'job_title', 'company_id', 'address', 'city', 'country', 'notes']))
            ->each->toBeNull();
    });

    it('treats empty fields as not given', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', [
                'first_name' => '  Solo  ', 'last_name' => '', 'email' => '', 'phone' => '', 'job_title' => '',
                'company_id' => '', 'address' => '', 'city' => '', 'country' => '', 'notes' => '',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $contact = ($this->stored)()->sole();

        expect($contact->first_name)->toBe('Solo')
            ->and($contact->only(['last_name', 'email', 'phone', 'job_title', 'company_id', 'address', 'city', 'country', 'notes']))
            ->each->toBeNull();
    });

    it('validates the request', function (array $input, string $field) {
        Event::fake([ContactCreated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', ($this->input)($input))
            ->assertSessionHasErrors($field);

        expect(($this->stored)())->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'contact.created'))->toBeEmpty();

        Event::assertNotDispatched(ContactCreated::class);
    })->with([
        'no first name' => [['first_name' => ''], 'first_name'],
        'first name only spaces' => [['first_name' => '   '], 'first_name'],
        'first name too long' => [['first_name' => str_repeat('a', 101)], 'first_name'],
        'last name too long' => [['last_name' => str_repeat('a', 101)], 'last_name'],
        'email that is not one' => [['email' => 'imran-at-northwind'], 'email'],
        'email too long' => [['email' => str_repeat('a', 250).'@x.test'], 'email'],
        'phone too long' => [['phone' => str_repeat('1', 41)], 'phone'],
        'job title too long' => [['job_title' => str_repeat('a', 121)], 'job_title'],
        'address too long' => [['address' => str_repeat('a', 501)], 'address'],
        'city too long' => [['city' => str_repeat('a', 121)], 'city'],
        'country in lower case' => [['country' => 'bd'], 'country'],
        'country of three letters' => [['country' => 'BGD'], 'country'],
        'country that is not letters' => [['country' => 'B1'], 'country'],
        'country as a name' => [['country' => 'Bangladesh'], 'country'],
        'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
        'company id of the wrong shape' => [['company_id' => 'abc'], 'company_id'],
        'owner id of the wrong shape' => [['owner_id' => 'abc'], 'owner_id'],
        'a list where text belongs' => [['first_name' => ['Imran']], 'first_name'],
    ]);

    it('accepts any two upper-case letters as a country', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', ($this->input)(['country' => 'GB']))
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)()->sole()->country)->toBe('GB');
    });

    it('keeps one live contact per email address in a workspace', function () {
        ($this->contact)(['email' => 'imran@northwind.test']);

        $this->actingAsMember($this->acme, $this->manager);

        foreach (['imran@northwind.test', 'IMRAN@Northwind.test'] as $email) {
            $this->post('http://acme.localhost/contacts', ($this->input)(['email' => $email]))
                ->assertSessionHasErrors('email');
        }

        expect(($this->stored)())->toHaveCount(1);
    });

    it('lets the address of a deleted contact be used again', function () {
        $gone = ($this->contact)(['email' => 'imran@northwind.test']);
        $this->inTenant($this->acme, fn () => $gone->delete());

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', ($this->input)(['email' => 'imran@northwind.test']))
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)()->sole()->id)->not->toBe($gone->id)
            ->and($this->inTenant($this->acme, fn () => Contact::withTrashed()->where('email', 'imran@northwind.test')->count()))->toBe(2);
    });

    it('accepts an address that a contact of another workspace has', function () {
        ($this->contact)(['email' => 'imran@northwind.test'], tenant: $this->globex);

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/contacts', ($this->input)(['email' => 'imran@northwind.test']))
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)())->toHaveCount(1)
            ->and($this->inTenant($this->globex, fn () => Contact::query()->count()))->toBe(1);
    });

    it('answers 404 for a company that is not in this workspace', function () {
        Event::fake([ContactCreated::class]);

        $theirs = ($this->company)([], $this->globex);
        $deleted = ($this->company)();
        $this->inTenant($this->acme, fn () => $deleted->delete());

        $this->actingAsMember($this->acme, $this->manager);

        foreach ([$theirs->id, $deleted->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            $this->post('http://acme.localhost/contacts', ($this->input)(['company_id' => $id]))->assertNotFound();
        }

        expect(($this->stored)())->toBeEmpty();

        Event::assertNotDispatched(ContactCreated::class);
    });
});

describe('changing a contact', function () {
    beforeEach(function () {
        $this->northwind = ($this->company)(['name' => 'Northwind Traders']);
        $this->imran = ($this->contact)(($this->input)(), $this->employee);
    });

    it('saves the changes and records old and new values', function () {
        Event::fake([ContactUpdated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)([
                'last_name' => 'Hossain Khan',
                'email' => 'Imran@Fabrikam.Test',
                'job_title' => null,
                'company_id' => $this->northwind->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Contact "Imran Hossain Khan" saved.');

        $contact = ($this->stored)()->sole();

        expect($contact->id)->toBe($this->imran->id)
            ->and($contact->last_name)->toBe('Hossain Khan')
            ->and($contact->email)->toBe('imran@fabrikam.test')
            ->and($contact->job_title)->toBeNull()
            ->and($contact->company_id)->toBe($this->northwind->id)
            // Untouched: the rest, and whose contact it is.
            ->and($contact->phone)->toBe('+8801711000001')
            ->and($contact->owner_id)->toBe($this->membershipOf($this->acme, $this->employee)->id);

        $entry = $this->auditTrail($this->acme, 'contact.updated')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->subject_type)->toBe('contact')
            ->and($entry->subject_id)->toBe($this->imran->id)
            ->and($entry->subject_label)->toBe('Imran Hossain Khan')
            ->and($entry->properties)->toEqual([
                'old' => [
                    'last_name' => 'Hossain',
                    'email' => 'imran@northwind.test',
                    'job_title' => 'Purchasing lead',
                    'company_id' => null,
                ],
                'attributes' => [
                    'last_name' => 'Hossain Khan',
                    'email' => 'imran@fabrikam.test',
                    'job_title' => null,
                    'company_id' => $this->northwind->id,
                ],
            ]);

        Event::assertDispatchedTimes(ContactUpdated::class, 1);
        Event::assertDispatched(ContactUpdated::class, fn (ContactUpdated $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'contact_id' => $this->imran->id,
                'company_id' => $this->northwind->id,
                'name' => 'Imran Hossain Khan',
                'email' => 'imran@fabrikam.test',
                'phone' => '+8801711000001',
            ]);
    });

    it('records and announces nothing when nothing changed', function () {
        Event::fake([ContactUpdated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, 'contact.updated'))->toBeEmpty();

        Event::assertNotDispatched(ContactUpdated::class);
    });

    it('lets a contact keep its own email, but not take the one of another', function () {
        ($this->contact)(['email' => 'nadia@fabrikam.test']);

        $this->actingAsMember($this->acme, $this->manager);

        $this->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)(['phone' => '+8801711999999']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)(['email' => 'Nadia@Fabrikam.test']))
            ->assertSessionHasErrors('email');

        $contact = $this->inTenant($this->acme, fn () => $this->imran->refresh());

        expect($contact->email)->toBe('imran@northwind.test')
            ->and($contact->phone)->toBe('+8801711999999');
    });

    it('validates the request', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)($input))
            ->assertSessionHasErrors($field);

        expect($this->inTenant($this->acme, fn () => $this->imran->refresh())->only(['first_name', 'email', 'country']))->toBe([
            'first_name' => 'Imran',
            'email' => 'imran@northwind.test',
            'country' => 'BD',
        ])
            ->and($this->auditTrail($this->acme, 'contact.updated'))->toBeEmpty();
    })->with([
        'no first name' => [['first_name' => ''], 'first_name'],
        'email that is not one' => [['email' => 'nope'], 'email'],
        'country in lower case' => [['country' => 'bd'], 'country'],
        'company id of the wrong shape' => [['company_id' => 'abc'], 'company_id'],
    ]);

    it('answers 404 for a company that is not in this workspace, and changes nothing', function () {
        $theirs = ($this->company)([], $this->globex);

        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)(['first_name' => 'Changed', 'company_id' => $theirs->id]))
            ->assertNotFound();

        $contact = $this->inTenant($this->acme, fn () => $this->imran->refresh());

        expect($contact->first_name)->toBe('Imran')
            ->and($contact->company_id)->toBeNull();
    });

    it('answers 404 for a contact that does not exist or was deleted', function () {
        $this->inTenant($this->acme, fn () => $this->imran->delete());

        $this->actingAsMember($this->acme, $this->admin);

        $this->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)())->assertNotFound();
        $this->delete("http://acme.localhost/contacts/{$this->imran->id}")->assertNotFound();
        $this->put('http://acme.localhost/contacts/01ARZ3NDEKTSV4RRFFQ69G5FAV', ($this->input)())->assertNotFound();
        $this->delete('http://acme.localhost/contacts/not-an-id')->assertNotFound();
    });
});

describe('deleting a contact', function () {
    it('keeps the record but takes it out of the directory', function () {
        Event::fake([ContactDeleted::class]);

        $company = ($this->company)();
        $imran = ($this->contact)(($this->input)(['company_id' => $company->id]), $this->employee);
        $nadia = ($this->contact)(['first_name' => 'Nadia', 'last_name' => 'Islam']);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/contacts/{$imran->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Contact deleted.');

        $this->inTenant($this->acme, function () use ($imran, $nadia, $company): void {
            $deleted = Contact::withTrashed()->findOrFail($imran->id);

            expect($deleted->trashed())->toBeTrue()
                // Soft: everything about the person is still there.
                ->and($deleted->email)->toBe('imran@northwind.test')
                ->and($deleted->company_id)->toBe($company->id)
                ->and(Contact::query()->pluck('id')->all())->toBe([$nadia->id])
                ->and(Company::query()->whereKey($company->id)->exists())->toBeTrue();
        });

        expect(($this->names)())->toBe(['Nadia Islam']);

        $entry = $this->auditTrail($this->acme, 'contact.deleted')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('contact')
            ->and($entry->subject_id)->toBe($imran->id)
            ->and($entry->subject_label)->toBe('Imran Hossain');

        Event::assertDispatchedTimes(ContactDeleted::class, 1);
        Event::assertDispatched(ContactDeleted::class, fn (ContactDeleted $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'contact_id' => $imran->id,
                'company_id' => $company->id,
                'name' => 'Imran Hossain',
                'email' => 'imran@northwind.test',
                'phone' => '+8801711000001',
            ]);
    });
});

describe('permissions', function () {
    beforeEach(function () {
        $this->imran = ($this->contact)(($this->input)());

        // Holds none of the three contact permissions.
        $this->outsider = $this->addMember($this->acme, $this->createRole($this->acme, 'Bookkeepers', ['core.members.view']));
        // May look, and nothing more.
        $this->viewer = $this->addMember($this->acme, $this->createRole($this->acme, 'Readers', ['core.contacts.view']));
        // May look and delete, but not change.
        $this->cleaner = $this->addMember($this->acme, $this->createRole($this->acme, 'Cleaners', ['core.contacts.view', 'core.contacts.delete']));
    });

    it('needs core.contacts.view to open the list', function () {
        $this->actingAsMember($this->acme, $this->outsider)
            ->get('http://acme.localhost/contacts')
            ->assertForbidden();

        foreach ([$this->viewer, $this->employee, $this->manager, $this->admin, $this->owner] as $member) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $member)
                ->get('http://acme.localhost/contacts')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('contacts.total', 1));
        }
    });

    it('needs core.contacts.manage to add or change a contact', function () {
        foreach ([$this->outsider, $this->viewer, $this->cleaner] as $member) {
            $this->startNewBrowserSession()->actingAsMember($this->acme, $member);

            $this->post('http://acme.localhost/contacts', ($this->input)(['first_name' => 'New', 'email' => 'new@northwind.test']))->assertForbidden();
            $this->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)(['first_name' => 'Changed']))->assertForbidden();
        }

        expect(($this->stored)()->pluck('first_name')->all())->toBe(['Imran']);

        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/contacts', ($this->input)(['first_name' => 'New', 'email' => 'new@northwind.test']))->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/contacts/{$this->imran->id}", ($this->input)(['first_name' => 'Changed']))->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)()->pluck('first_name')->all())->toBe(['Changed', 'New']);
    });

    it('needs core.contacts.delete to delete a contact', function () {
        // A Manager works with every contact, but deleting is not part of the role.
        foreach ([$this->outsider, $this->viewer, $this->employee, $this->manager] as $member) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $member)
                ->delete("http://acme.localhost/contacts/{$this->imran->id}")
                ->assertForbidden();
        }

        expect(($this->stored)())->toHaveCount(1);

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->cleaner)
            ->delete("http://acme.localhost/contacts/{$this->imran->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)())->toBeEmpty();
    });

    it('tells the page what the viewer may do', function (string $who, bool $canCreate, bool $editable, bool $deletable, bool $picksCompany, bool $picksOwner) {
        ($this->company)();

        $this->actingAsMember($this->acme, $this->{$who})
            ->get('http://acme.localhost/contacts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canCreate', $canCreate)
                ->where('contacts.data.0.editable', $editable)
                ->where('contacts.data.0.deletable', $deletable)
                ->has('companies', $picksCompany ? 1 : 0)
                // Owner, Admin, Manager, Employee and the three custom roles.
                ->has('owners', $picksOwner ? 7 : 0));
    })->with([
        'owner' => ['owner', true, true, true, true, true],
        'admin' => ['admin', true, true, true, true, true],
        'manager' => ['manager', true, true, false, true, true],
        // Adds contacts, but this one is nobody's - so not theirs to change.
        'employee' => ['employee', true, false, false, true, false],
        'view only' => ['viewer', false, false, false, false, false],
        'view and delete' => ['cleaner', false, false, true, false, false],
    ]);
});
