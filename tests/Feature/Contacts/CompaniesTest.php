<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Contacts\Events\CompanyCreated;
use App\Core\Contacts\Events\CompanyDeleted;
use App\Core\Contacts\Events\CompanyUpdated;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

/*
 * The organisations of the shared contact directory, over HTTP. They are
 * governed by the same three permissions as the people in it. Records are
 * made before anyone signs in: one made by a signed-in member becomes theirs.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->manager = $this->addMember($this->acme, Role::MANAGER);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);

    /** A company in a workspace (acme unless named), owned by `$owner` or by nobody. */
    $this->company = function (array $attributes = [], ?User $owner = null, ?Tenant $tenant = null): Company {
        $tenant ??= $this->acme;

        return $this->inTenant($tenant, function () use ($attributes, $owner, $tenant): Company {
            $company = Company::factory()->make($attributes);

            if ($owner !== null) {
                $company->assignOwner($this->membershipOf($tenant, $owner));
            }

            $company->save();

            return $company;
        });
    };

    $this->contact = fn (array $attributes = [], ?Tenant $tenant = null): Contact => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): Contact => Contact::factory()->create($attributes),
    );

    /** Names on the companies list as `$as` sees it, in the order shown. */
    $this->names = fn (string $query = '', ?User $as = null): array => array_column(
        $this->actingAsMember($this->acme, $as ?? $this->admin)
            ->get('http://acme.localhost/companies'.$query)
            ->assertOk()
            ->inertiaProps('companies.data'),
        'name',
    );

    /** Every company of acme that has not been deleted. */
    $this->stored = fn () => $this->inTenant($this->acme, fn () => Company::query()->orderBy('id')->get());

    /** Everything the form sends. */
    $this->input = fn (array $overrides = []): array => [
        'name' => 'Northwind Traders',
        'email' => 'hello@northwind.test',
        'phone' => '+880255500001',
        'website' => 'https://northwind.test',
        'industry' => 'Wholesale',
        'tax_id' => 'BIN-000123456',
        'address' => '45 Kawran Bazar',
        'city' => 'Dhaka',
        'country' => 'BD',
        'notes' => 'Pays by bank transfer.',
        ...$overrides,
    ];
});

describe('the companies list', function () {
    it('lists the companies of this workspace, and only them, with everything the page shows', function () {
        $company = ($this->company)(($this->input)(), $this->manager);
        $foreign = ($this->company)(['name' => 'Elsewhere Holdings'], tenant: $this->globex);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/companies')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company, $foreign): void {
                $page->component('Core/Pages/Contacts/Companies')
                    ->where('companies.total', 1)
                    ->where('companies.per_page', 25)
                    ->where('filters', ['search' => ''])
                    ->where('canCreate', true);

                $props = $page->toArray()['props'];

                expect($props['companies']['data'][0])->toEqual([
                    'id' => $company->id,
                    'name' => 'Northwind Traders',
                    'email' => 'hello@northwind.test',
                    'phone' => '+880255500001',
                    'website' => 'https://northwind.test',
                    'industry' => 'Wholesale',
                    'taxId' => 'BIN-000123456',
                    'address' => '45 Kawran Bazar',
                    'city' => 'Dhaka',
                    'country' => 'BD',
                    'notes' => 'Pays by bank transfer.',
                    'contacts' => 0,
                    'owner' => ['id' => $this->membershipOf($this->acme, $this->manager)->id, 'name' => $this->manager->name],
                    'editable' => true,
                    'deletable' => true,
                    'createdAt' => $company->created_at->toIso8601String(),
                ])
                    ->and(json_encode($props['companies']))->not->toContain($this->manager->id, $foreign->id, 'Elsewhere', 'tenant_id')
                    ->and(array_column($props['owners'], 'id'))->toEqualCanonicalizing([
                        $this->membershipOf($this->acme, $this->owner)->id,
                        $this->membershipOf($this->acme, $this->admin)->id,
                        $this->membershipOf($this->acme, $this->manager)->id,
                        $this->membershipOf($this->acme, $this->employee)->id,
                    ])
                    ->and(array_column($props['owners'], 'name'))->toBe(collect($props['owners'])->pluck('name')->sort()->values()->all());
            });
    });

    it('counts the people of each company: the live ones, of that company, in this workspace', function () {
        $northwind = ($this->company)(['name' => 'Northwind Traders']);
        $fabrikam = ($this->company)(['name' => 'Fabrikam']);
        ($this->company)(['name' => 'Empty Shell']);
        $theirs = ($this->company)(['name' => 'Northwind Traders'], tenant: $this->globex);

        ($this->contact)(['company_id' => $northwind->id]);
        ($this->contact)(['company_id' => $northwind->id]);
        ($this->contact)(['company_id' => $fabrikam->id]);
        ($this->contact)();
        ($this->contact)(['company_id' => $theirs->id], $this->globex);

        $deleted = ($this->contact)(['company_id' => $northwind->id]);
        $this->inTenant($this->acme, fn () => $deleted->delete());

        $counts = collect(
            $this->actingAsMember($this->acme, $this->admin)
                ->get('http://acme.localhost/companies')
                ->assertOk()
                ->inertiaProps('companies.data'),
        )->pluck('contacts', 'name')->all();

        expect($counts)->toBe(['Empty Shell' => 0, 'Fabrikam' => 1, 'Northwind Traders' => 2]);
    });

    it('searches by name, email and phone', function () {
        ($this->company)(['name' => 'Northwind Traders', 'email' => 'hello@northwind.test', 'phone' => '+880255500001']);
        ($this->company)(['name' => 'Fabrikam', 'email' => 'office@fabrikam.test', 'phone' => '+880299911222']);
        ($this->company)(['name' => 'Northwind Elsewhere', 'email' => 'hello@elsewhere.test'], tenant: $this->globex);

        $find = fn (string $term): array => ($this->names)('?search='.urlencode($term));

        expect($find('northwind'))->toBe(['Northwind Traders'])
            ->and($find('TRADERS'))->toBe(['Northwind Traders'])
            ->and($find('office@'))->toBe(['Fabrikam'])
            ->and($find('911222'))->toBe(['Fabrikam'])
            ->and($find('+88025'))->toBe(['Northwind Traders'])
            ->and($find('elsewhere'))->toBe([])
            // Blank is no search at all.
            ->and($find('   '))->toBe(['Fabrikam', 'Northwind Traders']);

        $this->get('http://acme.localhost/companies?search='.urlencode('  fabrikam '))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.search', 'fabrikam'));
    });

    it('searches for what was typed, wildcard characters included', function () {
        ($this->company)(['name' => 'Northwind Traders', 'email' => 'hello@northwind.test', 'phone' => '+880255500001']);
        ($this->company)(['name' => '100% Cotton_Mills', 'email' => null, 'phone' => null]);

        $find = fn (string $term): array => ($this->names)('?search='.urlencode($term));

        // On their own these would match everyone if they were passed through as wildcards.
        expect($find('%'))->toBe(['100% Cotton_Mills'])
            ->and($find('_'))->toBe(['100% Cotton_Mills'])
            ->and($find('Cotton_M'))->toBe(['100% Cotton_Mills'])
            ->and($find('Northwin_'))->toBe([])
            ->and($find('N%s'))->toBe([])
            ->and($find('\\'))->toBe([]);
    });

    it('rejects a malformed search instead of failing', function () {
        $this->actingAsMember($this->acme, $this->admin);

        $this->get('http://acme.localhost/companies?search[]=a')->assertSessionHasErrors('search');
        $this->get('http://acme.localhost/companies?search='.str_repeat('a', 101))->assertSessionHasErrors('search');
    });

    it('orders by name, then age, 25 to a page', function () {
        ($this->company)(['name' => 'Zenith Foods']);
        ($this->company)(['name' => 'Apex Textiles']);

        foreach (range(1, 26) as $number) {
            ($this->company)(['name' => sprintf('Meghna Unit %02d', $number)]);
        }

        // Two companies with the same name stay in the order they were added.
        $elder = ($this->company)(['name' => 'Apex Textiles']);
        $younger = ($this->company)(['name' => 'Apex Textiles']);

        $this->actingAsMember($this->acme, $this->admin)
            ->get('http://acme.localhost/companies')
            ->assertInertia(function (AssertableInertia $page) use ($elder, $younger): void {
                $page->where('companies.total', 30)
                    ->where('companies.per_page', 25)
                    ->where('companies.last_page', 2)
                    ->has('companies.data', 25);

                $rows = $page->toArray()['props']['companies']['data'];

                expect(array_column($rows, 'name'))->toBe([
                    'Apex Textiles', 'Apex Textiles', 'Apex Textiles',
                    ...array_map(fn (int $number): string => sprintf('Meghna Unit %02d', $number), range(1, 22)),
                ])
                    ->and(array_slice(array_column($rows, 'id'), 1, 2))->toBe([$elder->id, $younger->id]);
            });

        expect(($this->names)('?page=2'))->toBe(['Meghna Unit 23', 'Meghna Unit 24', 'Meghna Unit 25', 'Meghna Unit 26', 'Zenith Foods']);

        // Page links keep the search.
        $this->get('http://acme.localhost/companies?search=meghna')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies.total', 26)
                ->where('companies.next_page_url', fn (string $url): bool => str_contains($url, 'search=meghna') && str_contains($url, 'page=2')));
    });
});

describe('adding a company', function () {
    it('stores every field, lower-cases the email, and makes the person who added it the owner', function () {
        Event::fake([CompanyCreated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/companies', ($this->input)(['email' => 'Hello@Northwind.TEST']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Company "Northwind Traders" added.');

        $company = ($this->stored)()->sole();

        expect($company->only(['name', 'email', 'phone', 'website', 'industry', 'tax_id', 'address', 'city', 'country', 'notes']))->toBe([
            'name' => 'Northwind Traders',
            'email' => 'hello@northwind.test',
            'phone' => '+880255500001',
            'website' => 'https://northwind.test',
            'industry' => 'Wholesale',
            'tax_id' => 'BIN-000123456',
            'address' => '45 Kawran Bazar',
            'city' => 'Dhaka',
            'country' => 'BD',
            'notes' => 'Pays by bank transfer.',
        ])
            ->and($company->tenant_id)->toBe($this->acme->id)
            ->and($company->owner_id)->toBe($this->membershipOf($this->acme, $this->manager)->id);

        $entry = $this->auditTrail($this->acme, 'company.created')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->subject_type)->toBe('company')
            ->and($entry->subject_id)->toBe($company->id)
            ->and($entry->subject_label)->toBe('Northwind Traders')
            ->and($entry->properties['attributes'])->toMatchArray([
                'name' => 'Northwind Traders',
                'email' => 'hello@northwind.test',
                'owner_id' => $company->owner_id,
            ]);

        Event::assertDispatchedTimes(CompanyCreated::class, 1);
        Event::assertDispatched(CompanyCreated::class, fn (CompanyCreated $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'company_id' => $company->id,
                'name' => 'Northwind Traders',
                'email' => 'hello@northwind.test',
                'phone' => '+880255500001',
            ]);
    });

    it('needs nothing but a name, and treats empty fields as not given', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/companies', [
                'name' => '  Fabrikam  ', 'email' => '', 'phone' => '', 'website' => '', 'industry' => '',
                'tax_id' => '', 'address' => '', 'city' => '', 'country' => '', 'notes' => '',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $company = ($this->stored)()->sole();

        expect($company->name)->toBe('Fabrikam')
            ->and($company->only(['email', 'phone', 'website', 'industry', 'tax_id', 'address', 'city', 'country', 'notes']))
            ->each->toBeNull();
    });

    it('lets two companies share a name or an email address', function () {
        ($this->company)(($this->input)());

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/companies', ($this->input)())
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)())->toHaveCount(2);
    });

    it('validates the request', function (array $input, string $field) {
        Event::fake([CompanyCreated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/companies', ($this->input)($input))
            ->assertSessionHasErrors($field);

        expect(($this->stored)())->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'company.created'))->toBeEmpty();

        Event::assertNotDispatched(CompanyCreated::class);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'name only spaces' => [['name' => '   '], 'name'],
        'name too long' => [['name' => str_repeat('a', 201)], 'name'],
        'email that is not one' => [['email' => 'hello-at-northwind'], 'email'],
        'phone too long' => [['phone' => str_repeat('1', 41)], 'phone'],
        'website without a scheme' => [['website' => 'northwind.test'], 'website'],
        'website that is not http' => [['website' => 'ftp://northwind.test'], 'website'],
        'website that is a script' => [['website' => 'javascript:alert(1)'], 'website'],
        'website too long' => [['website' => 'https://northwind.test/'.str_repeat('a', 240)], 'website'],
        'industry too long' => [['industry' => str_repeat('a', 121)], 'industry'],
        'tax id too long' => [['tax_id' => str_repeat('1', 61)], 'tax_id'],
        'address too long' => [['address' => str_repeat('a', 501)], 'address'],
        'city too long' => [['city' => str_repeat('a', 121)], 'city'],
        'country in lower case' => [['country' => 'bd'], 'country'],
        'country of three letters' => [['country' => 'BGD'], 'country'],
        'country that is not letters' => [['country' => 'B1'], 'country'],
        'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
        'owner id of the wrong shape' => [['owner_id' => 'abc'], 'owner_id'],
        'a list where text belongs' => [['name' => ['Northwind']], 'name'],
    ]);
});

describe('changing a company', function () {
    beforeEach(function () {
        $this->northwind = ($this->company)(($this->input)(), $this->employee);
    });

    it('saves the changes and records old and new values', function () {
        Event::fake([CompanyUpdated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/companies/{$this->northwind->id}", ($this->input)([
                'name' => 'Northwind Trading Co.',
                'email' => 'Accounts@Northwind.Test',
                'industry' => null,
                'country' => 'IN',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Company "Northwind Trading Co." saved.');

        $company = ($this->stored)()->sole();

        expect($company->id)->toBe($this->northwind->id)
            ->and($company->name)->toBe('Northwind Trading Co.')
            ->and($company->email)->toBe('accounts@northwind.test')
            ->and($company->industry)->toBeNull()
            ->and($company->country)->toBe('IN')
            // Untouched: the rest, and whose company it is.
            ->and($company->tax_id)->toBe('BIN-000123456')
            ->and($company->owner_id)->toBe($this->membershipOf($this->acme, $this->employee)->id);

        $entry = $this->auditTrail($this->acme, 'company.updated')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->subject_type)->toBe('company')
            ->and($entry->subject_id)->toBe($this->northwind->id)
            ->and($entry->subject_label)->toBe('Northwind Trading Co.')
            ->and($entry->properties)->toEqual([
                'old' => [
                    'name' => 'Northwind Traders',
                    'email' => 'hello@northwind.test',
                    'industry' => 'Wholesale',
                    'country' => 'BD',
                ],
                'attributes' => [
                    'name' => 'Northwind Trading Co.',
                    'email' => 'accounts@northwind.test',
                    'industry' => null,
                    'country' => 'IN',
                ],
            ]);

        Event::assertDispatchedTimes(CompanyUpdated::class, 1);
        Event::assertDispatched(CompanyUpdated::class, fn (CompanyUpdated $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'company_id' => $this->northwind->id,
                'name' => 'Northwind Trading Co.',
                'email' => 'accounts@northwind.test',
                'phone' => '+880255500001',
            ]);
    });

    it('records and announces nothing when nothing changed', function () {
        Event::fake([CompanyUpdated::class]);

        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/companies/{$this->northwind->id}", ($this->input)())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, 'company.updated'))->toBeEmpty();

        Event::assertNotDispatched(CompanyUpdated::class);
    });

    it('validates the request', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->manager)
            ->put("http://acme.localhost/companies/{$this->northwind->id}", ($this->input)($input))
            ->assertSessionHasErrors($field);

        expect($this->inTenant($this->acme, fn () => $this->northwind->refresh())->only(['name', 'website', 'country']))->toBe([
            'name' => 'Northwind Traders',
            'website' => 'https://northwind.test',
            'country' => 'BD',
        ])
            ->and($this->auditTrail($this->acme, 'company.updated'))->toBeEmpty();
    })->with([
        'no name' => [['name' => ''], 'name'],
        'email that is not one' => [['email' => 'nope'], 'email'],
        'website that is not http' => [['website' => 'ftp://northwind.test'], 'website'],
        'country in lower case' => [['country' => 'bd'], 'country'],
    ]);

    it('answers 404 for a company that does not exist or was deleted', function () {
        $this->inTenant($this->acme, fn () => $this->northwind->delete());

        $this->actingAsMember($this->acme, $this->admin);

        $this->put("http://acme.localhost/companies/{$this->northwind->id}", ($this->input)())->assertNotFound();
        $this->delete("http://acme.localhost/companies/{$this->northwind->id}")->assertNotFound();
        $this->put('http://acme.localhost/companies/01ARZ3NDEKTSV4RRFFQ69G5FAV', ($this->input)())->assertNotFound();
        $this->delete('http://acme.localhost/companies/not-an-id')->assertNotFound();
    });
});

describe('deleting a company', function () {
    it('keeps the record but takes it out of the directory', function () {
        Event::fake([CompanyDeleted::class]);

        $northwind = ($this->company)(($this->input)(), $this->employee);
        $fabrikam = ($this->company)(['name' => 'Fabrikam']);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/companies/{$northwind->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Company deleted.');

        $this->inTenant($this->acme, function () use ($northwind, $fabrikam): void {
            $deleted = Company::withTrashed()->findOrFail($northwind->id);

            expect($deleted->trashed())->toBeTrue()
                // Soft: everything about the company is still there.
                ->and($deleted->email)->toBe('hello@northwind.test')
                ->and(Company::query()->pluck('id')->all())->toBe([$fabrikam->id]);
        });

        expect(($this->names)())->toBe(['Fabrikam']);

        $entry = $this->auditTrail($this->acme, 'company.deleted')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('company')
            ->and($entry->subject_id)->toBe($northwind->id)
            ->and($entry->subject_label)->toBe('Northwind Traders');

        Event::assertDispatchedTimes(CompanyDeleted::class, 1);
        Event::assertDispatched(CompanyDeleted::class, fn (CompanyDeleted $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'company_id' => $northwind->id,
                'name' => 'Northwind Traders',
                'email' => 'hello@northwind.test',
                'phone' => '+880255500001',
            ]);
    });

    it('keeps its people in the directory, without a company', function () {
        $northwind = ($this->company)(['name' => 'Northwind Traders']);
        $fabrikam = ($this->company)(['name' => 'Fabrikam']);
        $theirs = ($this->company)(['name' => 'Northwind Traders'], tenant: $this->globex);

        $imran = ($this->contact)(['first_name' => 'Imran', 'company_id' => $northwind->id]);
        $nadia = ($this->contact)(['first_name' => 'Nadia', 'company_id' => $northwind->id]);
        $rafiq = ($this->contact)(['first_name' => 'Rafiq', 'company_id' => $fabrikam->id]);
        $foreign = ($this->contact)(['first_name' => 'Their', 'company_id' => $theirs->id], $this->globex);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/companies/{$northwind->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant($this->acme, function () use ($imran, $nadia, $rafiq, $fabrikam): void {
            expect(Contact::query()->orderBy('first_name')->pluck('company_id', 'first_name')->all())->toBe([
                'Imran' => null,
                'Nadia' => null,
                'Rafiq' => $fabrikam->id,
            ])
                ->and(Contact::withTrashed()->count())->toBe(3)
                ->and(Contact::onlyTrashed()->count())->toBe(0)
                ->and($imran->refresh()->first_name)->toBe('Imran')
                ->and($nadia->refresh()->email)->toBe($nadia->email)
                ->and($rafiq->refresh()->company_id)->toBe($fabrikam->id);
        });

        // Another workspace's company of the same name, and its people, are untouched.
        $this->inTenant($this->globex, function () use ($foreign, $theirs): void {
            expect($foreign->refresh()->company_id)->toBe($theirs->id)
                ->and(Company::query()->whereKey($theirs->id)->exists())->toBeTrue();
        });

        // The people list no longer names the company, and the filter no longer finds it.
        $this->get('http://acme.localhost/contacts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contacts.total', 3)
                ->where('contacts.data.0.company', null)
                ->where('contacts.data.1.company', null)
                ->where('contacts.data.2.company.id', $fabrikam->id)
                ->where('companies', [['id' => $fabrikam->id, 'name' => 'Fabrikam']]));

        $this->get("http://acme.localhost/contacts?company={$northwind->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.company', null));
    });
});

describe('permissions', function () {
    beforeEach(function () {
        $this->northwind = ($this->company)(($this->input)());

        // Holds none of the three contact permissions.
        $this->outsider = $this->addMember($this->acme, $this->createRole($this->acme, 'Bookkeepers', ['core.members.view']));
        // May look, and nothing more.
        $this->viewer = $this->addMember($this->acme, $this->createRole($this->acme, 'Readers', ['core.contacts.view']));
        // May look and delete, but not change.
        $this->cleaner = $this->addMember($this->acme, $this->createRole($this->acme, 'Cleaners', ['core.contacts.view', 'core.contacts.delete']));
    });

    it('needs core.contacts.view to open the list', function () {
        $this->actingAsMember($this->acme, $this->outsider)
            ->get('http://acme.localhost/companies')
            ->assertForbidden();

        foreach ([$this->viewer, $this->employee, $this->manager, $this->admin, $this->owner] as $member) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $member)
                ->get('http://acme.localhost/companies')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('companies.total', 1));
        }
    });

    it('needs core.contacts.manage to add or change a company', function () {
        foreach ([$this->outsider, $this->viewer, $this->cleaner] as $member) {
            $this->startNewBrowserSession()->actingAsMember($this->acme, $member);

            $this->post('http://acme.localhost/companies', ($this->input)(['name' => 'New Co']))->assertForbidden();
            $this->put("http://acme.localhost/companies/{$this->northwind->id}", ($this->input)(['name' => 'Changed Co']))->assertForbidden();
        }

        expect(($this->stored)()->pluck('name')->all())->toBe(['Northwind Traders']);

        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/companies', ($this->input)(['name' => 'New Co']))->assertRedirect()->assertSessionHasNoErrors();
        $this->put("http://acme.localhost/companies/{$this->northwind->id}", ($this->input)(['name' => 'Changed Co']))->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)()->pluck('name')->all())->toBe(['Changed Co', 'New Co']);
    });

    it('needs core.contacts.delete to delete a company', function () {
        // A Manager works with every company, but deleting is not part of the role.
        foreach ([$this->outsider, $this->viewer, $this->employee, $this->manager] as $member) {
            $this->startNewBrowserSession()
                ->actingAsMember($this->acme, $member)
                ->delete("http://acme.localhost/companies/{$this->northwind->id}")
                ->assertForbidden();
        }

        expect(($this->stored)())->toHaveCount(1);

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->cleaner)
            ->delete("http://acme.localhost/companies/{$this->northwind->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->stored)())->toBeEmpty();
    });

    it('tells the page what the viewer may do', function (string $who, bool $canCreate, bool $editable, bool $deletable, bool $picksOwner) {
        $this->actingAsMember($this->acme, $this->{$who})
            ->get('http://acme.localhost/companies')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canCreate', $canCreate)
                ->where('companies.data.0.editable', $editable)
                ->where('companies.data.0.deletable', $deletable)
                // Owner, Admin, Manager, Employee and the three custom roles.
                ->has('owners', $picksOwner ? 7 : 0));
    })->with([
        'owner' => ['owner', true, true, true, true],
        'admin' => ['admin', true, true, true, true],
        'manager' => ['manager', true, true, false, true],
        // Adds companies, but this one is nobody's - so not theirs to change.
        'employee' => ['employee', true, false, false, false],
        'view only' => ['viewer', false, false, false, false],
        'view and delete' => ['cleaner', false, false, true, false],
    ]);
});
