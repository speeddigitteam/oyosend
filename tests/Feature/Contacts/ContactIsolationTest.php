<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Models\Tenant;

/*
 * The contact directory belongs to one workspace: the row scope hides it from
 * every other, and its routes answer 404 from another workspace's host.
 */
it('isolates contacts between tenants', function () {
    $this->assertTenantIsolated(Contact::class, fn (Tenant $tenant) => Contact::factory()->create());
});

it('isolates companies between tenants', function () {
    $this->assertTenantIsolated(Company::class, fn (Tenant $tenant) => Company::factory()->create());
});

it('isolates a contact that sits in a company, and deleted records too', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');

    [$company, $contact, $deleted] = $this->inTenant($acme, function (): array {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $deleted = Contact::factory()->create();
        $deleted->delete();

        return [$company, $contact, $deleted];
    });

    $this->inTenant($globex, function () use ($company, $contact, $deleted): void {
        expect(Contact::withTrashed()->find($contact->id))->toBeNull()
            ->and(Contact::withTrashed()->find($deleted->id))->toBeNull()
            ->and(Contact::onlyTrashed()->count())->toBe(0)
            ->and(Company::withTrashed()->find($company->id))->toBeNull()
            ->and(Contact::query()->where('company_id', $company->id)->count())->toBe(0);
    });

    $this->inTenant($acme, function () use ($company, $contact, $deleted): void {
        expect($company->contacts()->pluck('id')->all())->toBe([$contact->id])
            ->and(Contact::onlyTrashed()->pluck('id')->all())->toBe([$deleted->id]);
    });
});

describe('across workspaces', function () {
    beforeEach(function () {
        $this->acme = $this->createTenant('acme');

        $this->company = $this->inTenant($this->acme, fn (): Company => Company::factory()->create(['name' => 'Northwind Traders']));
        $this->contact = $this->inTenant($this->acme, fn (): Contact => Contact::factory()->create([
            'first_name' => 'Imran',
            'company_id' => $this->company->id,
        ]));
    });

    it('answers 404 for a contact from another workspace', function (string $method, array $data) {
        $this->assertNotFoundFromOtherTenant($method, "/contacts/{$this->contact->id}", $this->acme, $data);
    })->with([
        'update' => ['PUT', ['first_name' => 'Taken']],
        'delete' => ['DELETE', []],
    ]);

    it('answers 404 for a company from another workspace', function (string $method, array $data) {
        $this->assertNotFoundFromOtherTenant($method, "/companies/{$this->company->id}", $this->acme, $data);
    })->with([
        'update' => ['PUT', ['name' => 'Taken Co']],
        'delete' => ['DELETE', []],
    ]);

    it('leaves the records untouched by an attempt from another workspace', function () {
        $globex = $this->createTenant('globex');

        $this->actingAsOwner($globex);

        $this->put("http://globex.localhost/contacts/{$this->contact->id}", ['first_name' => 'Taken'])->assertNotFound();
        $this->delete("http://globex.localhost/contacts/{$this->contact->id}")->assertNotFound();
        $this->put("http://globex.localhost/companies/{$this->company->id}", ['name' => 'Taken Co'])->assertNotFound();
        $this->delete("http://globex.localhost/companies/{$this->company->id}")->assertNotFound();

        $this->inTenant($this->acme, function (): void {
            expect($this->contact->refresh()->first_name)->toBe('Imran')
                ->and($this->contact->trashed())->toBeFalse()
                ->and($this->company->refresh()->name)->toBe('Northwind Traders')
                ->and($this->company->trashed())->toBeFalse();
        });
    });

    it('does not let another workspace see, search, filter by or attach to them', function () {
        $globex = $this->createTenant('globex');

        $this->actingAsOwner($globex);

        $this->get('http://globex.localhost/contacts?search=Imran')->assertOk()->assertInertia(fn ($page) => $page
            ->where('contacts.total', 0)
            ->where('companies', []));
        $this->get("http://globex.localhost/contacts?company={$this->company->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('contacts.total', 0)
            ->where('filters.company', null));
        $this->get('http://globex.localhost/companies?search=Northwind')->assertOk()->assertInertia(fn ($page) => $page
            ->where('companies.total', 0));

        $this->post('http://globex.localhost/contacts', ['first_name' => 'Planted', 'company_id' => $this->company->id])->assertNotFound();

        expect($this->inTenant($globex, fn () => Contact::query()->count()))->toBe(0)
            ->and($this->inTenant($this->acme, fn () => $this->company->contacts()->count()))->toBe(1);
    });

    it('does not hand a record to a member of another workspace', function () {
        $globex = $this->createTenant('globex');
        $theirOwner = $this->membershipOf($globex, $this->ownerOf($globex));

        $this->actingAsOwner($this->acme);

        $this->put("http://acme.localhost/contacts/{$this->contact->id}", ['first_name' => 'Imran', 'owner_id' => $theirOwner->id])->assertNotFound();
        $this->put("http://acme.localhost/companies/{$this->company->id}", ['name' => 'Northwind Traders', 'owner_id' => $theirOwner->id])->assertNotFound();

        $this->inTenant($this->acme, function () use ($theirOwner): void {
            expect($this->contact->refresh()->owner_id)->not->toBe($theirOwner->id)
                ->and($this->company->refresh()->owner_id)->not->toBe($theirOwner->id);
        });
    });
});
