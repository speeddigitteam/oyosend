<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia;

/*
 * A company's own page (Odoo's company form): what is recorded about it and the people of it the viewer can see,
 * where another person is added with the company already chosen.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);

    $this->company = fn (array $attributes = [], ?Tenant $tenant = null): Company => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): Company => Company::factory()->create($attributes),
    );

    /** A contact in acme, owned by `$owner` or by nobody. */
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

    $this->page = fn (Company $company): string => "http://acme.localhost/companies/{$company->id}";
});

it('shows the company and only its own people, by name, with what the viewer may do', function () {
    $northwind = ($this->company)(['name' => 'Northwind Traders', 'industry' => 'Wholesale', 'city' => 'Dhaka']);
    $other = ($this->company)(['name' => 'Fabrikam']);
    ($this->contact)(['first_name' => 'Nadia', 'last_name' => 'Islam', 'company_id' => $northwind->id]);
    ($this->contact)(['first_name' => 'Imran', 'last_name' => 'Hossain', 'company_id' => $northwind->id]);
    ($this->contact)(['first_name' => 'Rafiq', 'last_name' => 'Ahmed', 'company_id' => $other->id]);
    ($this->contact)(['first_name' => 'Solo', 'last_name' => 'Trader']);

    $this->actingAsMember($this->acme, $this->admin)
        ->get(($this->page)($northwind))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Contacts/CompanyShow')
            ->where('company.id', $northwind->id)
            ->where('company.name', 'Northwind Traders')
            ->where('company.industry', 'Wholesale')
            ->where('company.contacts', 2)
            ->where('company.editable', true)
            ->where('company.deletable', true)
            ->where('people', fn ($people): bool => collect($people)->pluck('name')->all() === ['Imran Hossain', 'Nadia Islam'])
            ->where('people.0.company', ['id' => $northwind->id, 'name' => 'Northwind Traders'])
            ->where('canCreate', true)
            ->has('companies')
            ->has('owners'));
});

it('lets someone who may only look see the page, but not change or add anything', function () {
    $northwind = ($this->company)(['name' => 'Northwind Traders']);
    $viewer = $this->addMember($this->acme, $this->inTenant($this->acme, fn (): Role => app(RoleManager::class)
        ->create($this->owner, 'Readers', null, ['core.contacts.view'], ['core.contacts.view' => 'all'])));

    $this->actingAsMember($this->acme, $viewer)
        ->get(($this->page)($northwind))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('company.editable', false)
            ->where('company.deletable', false)
            ->where('canCreate', false)
            ->where('companies', [])
            ->where('owners', []));
});

it('shows only the people of the company the viewer\'s scope reaches, and counts only them', function () {
    $northwind = ($this->company)(['name' => 'Northwind Traders']);
    $lead = $this->addMember($this->acme, $this->inTenant($this->acme, fn (): Role => app(RoleManager::class)
        ->create($this->owner, 'Own only', null, ['core.contacts.view'], ['core.contacts.view' => 'own'])));
    ($this->contact)(['first_name' => 'Mine', 'last_name' => 'One', 'company_id' => $northwind->id], $lead);
    ($this->contact)(['first_name' => 'Not', 'last_name' => 'Mine', 'company_id' => $northwind->id], $this->admin);
    $theirs = ($this->company)(['name' => 'Somebody Else Ltd']);
    $this->inTenant($this->acme, function () use ($theirs, $lead): void {
        $northwind = Company::query()->where('name', 'Northwind Traders')->sole();
        $northwind->assignOwner($this->membershipOf($this->acme, $lead));
        $northwind->save();
        $theirs->assignOwner($this->membershipOf($this->acme, $this->admin));
        $theirs->save();
    });

    $this->actingAsMember($this->acme, $lead)
        ->get(($this->page)($northwind))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('company.contacts', 1)
            ->where('people', fn ($people): bool => collect($people)->pluck('name')->all() === ['Mine One']));

    // A company outside the scope is not disclosed to exist.
    $this->actingAsMember($this->acme, $lead)->get(($this->page)($theirs))->assertNotFound();
});

it('answers 404 for another workspace\'s company, and for one that was deleted', function () {
    $theirs = ($this->company)(['name' => 'Globex Holdings'], $this->globex);
    $gone = ($this->company)(['name' => 'Gone Ltd']);
    $this->inTenant($this->acme, fn () => $gone->delete());

    $this->assertNotFoundFromOtherTenant('GET', "/companies/{$theirs->id}", $this->globex, [], $this->acme);
    $this->actingAsMember($this->acme, $this->admin)->get(($this->page)($gone))->assertNotFound();
});

it('goes back to the companies list after the company is deleted from its own page, and stays on the list otherwise', function () {
    $fromPage = ($this->company)(['name' => 'From Its Page']);
    $fromList = ($this->company)(['name' => 'From The List']);

    $this->actingAsMember($this->acme, $this->admin)
        ->from(($this->page)($fromPage))
        ->delete("http://acme.localhost/companies/{$fromPage->id}")
        ->assertRedirect('http://acme.localhost/companies');

    $this->actingAsMember($this->acme, $this->admin)
        ->from('http://acme.localhost/companies?search=from')
        ->delete("http://acme.localhost/companies/{$fromList->id}")
        ->assertRedirect('http://acme.localhost/companies?search=from');
});
