<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Enums\AddressType;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Models\ContactAddress;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia;

/*
 * Typed addresses of companies and contacts (ADR 0039): Odoo's invoice, delivery and other addresses, each with its own
 * person, phone and email. Written through ContactDirectory, authorized against the parent record.
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);

    $this->company = fn (array $attributes = [], ?Tenant $tenant = null, ?User $owner = null): Company => $this->inTenant(
        $tenant ?? $this->acme,
        function () use ($attributes, $tenant, $owner): Company {
            $company = Company::factory()->make($attributes);

            if ($owner !== null) {
                $company->assignOwner($this->membershipOf($tenant ?? $this->acme, $owner));
            }

            $company->save();

            return $company;
        },
    );

    $this->contact = fn (array $attributes = [], ?Tenant $tenant = null): Contact => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): Contact => Contact::factory()->create($attributes),
    );

    /** Adds an address directly, as a module or a seeder would. */
    $this->address = fn (Company|Contact $parent, array $attributes = [], ?Tenant $tenant = null): ContactAddress => $this->inTenant(
        $tenant ?? $this->acme,
        fn (): ContactAddress => app(ContactDirectory::class)->addAddress($parent, [
            'type' => 'invoice',
            'address' => 'House 12, Road 5',
            ...$attributes,
        ]),
    );

    $this->fresh = fn (ContactAddress $address): ContactAddress => $this->inTenant(
        $this->acme,
        fn (): ContactAddress => ContactAddress::query()->withTrashed()->findOrFail($address->id),
    );

    $this->url = fn (string $path): string => "http://acme.localhost{$path}";

    $this->role = fn (string $name, array $permissions, array $scopes): User => $this->addMember(
        $this->acme,
        $this->inTenant($this->acme, fn (): Role => app(RoleManager::class)->create($this->owner, $name, null, $permissions, $scopes)),
    );
});

it('keeps addresses inside their workspace', function () {
    $this->assertTenantIsolated(ContactAddress::class, fn (): ContactAddress => app(ContactDirectory::class)->addAddress(
        Company::factory()->create(),
        ['type' => 'delivery', 'city' => 'Dhaka'],
    ));
});

it('adds an address to a company, and makes the first of each type its default', function () {
    $company = ($this->company)(['name' => 'Northwind']);

    $this->actingAsMember($this->acme, $this->admin)
        ->post(($this->url)("/companies/{$company->id}/addresses"), [
            'type' => 'invoice',
            'label' => 'Head office',
            'name' => 'Accounts payable',
            'email' => 'AP@Northwind.example',
            'phone' => '+8801711000000',
            'address' => "House 12, Road 5\nGulshan",
            'city' => 'Dhaka',
            'postal_code' => '1212',
            'country' => 'BD',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAsMember($this->acme, $this->admin)
        ->post(($this->url)("/companies/{$company->id}/addresses"), ['type' => 'invoice', 'label' => 'Branch', 'city' => 'Chattogram'])
        ->assertSessionHasNoErrors();

    $addresses = $this->inTenant($this->acme, fn () => ContactAddress::query()->orderBy('created_at')->orderBy('id')->get());

    expect($addresses)->toHaveCount(2)
        ->and($addresses[0]->company_id)->toBe($company->id)
        ->and($addresses[0]->contact_id)->toBeNull()
        ->and($addresses[0]->type)->toBe(AddressType::Invoice)
        ->and($addresses[0]->email)->toBe('ap@northwind.example')
        ->and($addresses[0]->postal_code)->toBe('1212')
        ->and($addresses[0]->is_default)->toBeTrue()
        ->and($addresses[1]->is_default)->toBeFalse();
});

it('hands the default over when another address of the type is made the default, and only within that type', function () {
    $company = ($this->company)();
    $first = ($this->address)($company, ['label' => 'First']);
    $second = ($this->address)($company, ['label' => 'Second']);
    $delivery = ($this->address)($company, ['type' => 'delivery', 'label' => 'Warehouse']);

    $this->actingAsMember($this->acme, $this->admin)
        ->post(($this->url)("/addresses/{$second->id}/default"))
        ->assertRedirect();

    expect(($this->fresh)($first)->is_default)->toBeFalse()
        ->and(($this->fresh)($second)->is_default)->toBeTrue()
        ->and(($this->fresh)($delivery)->is_default)->toBeTrue();

    // Adding one that asks to be the default takes it over as well.
    $third = ($this->address)($company, ['label' => 'Third', 'is_default' => true]);

    expect(($this->fresh)($second)->is_default)->toBeFalse()
        ->and(($this->fresh)($third)->is_default)->toBeTrue();
});

it('promotes the oldest address left when the default is deleted', function () {
    $company = ($this->company)();
    $default = ($this->address)($company, ['label' => 'Default']);
    $older = ($this->address)($company, ['label' => 'Older']);
    $this->travel(1)->minute();
    $newer = ($this->address)($company, ['label' => 'Newer']);

    $this->actingAsMember($this->acme, $this->admin)
        ->delete(($this->url)("/addresses/{$default->id}"))
        ->assertRedirect();

    expect(($this->fresh)($default)->trashed())->toBeTrue()
        ->and(($this->fresh)($older)->is_default)->toBeTrue()
        ->and(($this->fresh)($newer)->is_default)->toBeFalse();
});

it('decides from the address as it is under the lock, not the copy read before it', function () {
    $company = ($this->company)();
    $first = ($this->address)($company, ['label' => 'First']);
    $this->travel(1)->minute();
    $second = ($this->address)($company, ['label' => 'Second']);
    $this->travel(1)->minute();
    $third = ($this->address)($company, ['label' => 'Third']);

    $this->inTenant($this->acme, function () use ($first, $second, $third): void {
        $directory = app(ContactDirectory::class);
        // Two requests bound "Second" while it was not the default; one deletes "First" and promotes "Second" first.
        $staleForDelete = ContactAddress::query()->findOrFail($second->id);
        $staleForDefault = ContactAddress::query()->findOrFail($second->id);
        $directory->deleteAddress($first);

        $directory->deleteAddress($staleForDelete);

        expect(ContactAddress::query()->findOrFail($third->id)->is_default)->toBeTrue();
        expect(fn () => $directory->makeDefaultAddress($staleForDefault))->toThrow(ModelNotFoundException::class);
    });
});

it('moves an address to another type, handing its old default on and taking the new one only when it is the first', function () {
    $company = ($this->company)();
    $invoice = ($this->address)($company, ['label' => 'Invoice A']);
    $invoiceB = ($this->address)($company, ['label' => 'Invoice B']);

    $this->actingAsMember($this->acme, $this->admin)
        ->put(($this->url)("/addresses/{$invoice->id}"), ['type' => 'delivery', 'label' => 'Now a warehouse', 'city' => 'Gazipur'])
        ->assertSessionHasNoErrors();

    $moved = ($this->fresh)($invoice);

    expect($moved->type)->toBe(AddressType::Delivery)
        ->and($moved->is_default)->toBeTrue()
        ->and($moved->label)->toBe('Now a warehouse')
        ->and($moved->address)->toBeNull()
        ->and(($this->fresh)($invoiceB)->is_default)->toBeTrue();

    // Turning the default off is not a change on its own: the type keeps one default.
    $this->actingAsMember($this->acme, $this->admin)
        ->put(($this->url)("/addresses/{$invoiceB->id}"), ['type' => 'invoice', 'label' => 'Invoice B', 'is_default' => false])
        ->assertSessionHasNoErrors();

    expect(($this->fresh)($invoiceB)->is_default)->toBeTrue();
});

it('validates the address', function () {
    $company = ($this->company)();
    $post = fn (array $data) => $this->actingAsMember($this->acme, $this->admin)
        ->post(($this->url)("/companies/{$company->id}/addresses"), $data);

    $post(['type' => 'invoice'])->assertSessionHasErrors('address');
    $post(['type' => 'billing', 'city' => 'Dhaka'])->assertSessionHasErrors('type');
    $post(['city' => 'Dhaka'])->assertSessionHasErrors('type');
    $post(['type' => 'invoice', 'city' => 'Dhaka', 'country' => 'bd'])->assertSessionHasErrors('country');
    $post(['type' => 'invoice', 'city' => 'Dhaka', 'country' => 'BGD'])->assertSessionHasErrors('country');
    $post(['type' => 'invoice', 'email' => 'not an email'])->assertSessionHasErrors('email');
    $post(['type' => 'invoice', 'postal_code' => str_repeat('1', 21)])->assertSessionHasErrors('postal_code');
    $post(['type' => 'invoice', 'phone' => str_repeat('1', 41)])->assertSessionHasErrors('phone');
    $post(['type' => 'invoice', 'address' => str_repeat('a', 1001)])->assertSessionHasErrors('address');

    expect($this->inTenant($this->acme, fn (): int => ContactAddress::query()->count()))->toBe(0);
});

it('gives every address exactly one parent', function () {
    $company = ($this->company)();
    $contact = ($this->contact)();

    // The parent is the record it is added to; ids in the attributes are not taken.
    $address = ($this->address)($company, ['contact_id' => $contact->id, 'company_id' => $contact->id]);

    expect($address->company_id)->toBe($company->id)
        ->and($address->contact_id)->toBeNull();

    // An unsaved parent, or another workspace's, is refused.
    $this->inTenant($this->acme, fn () => expect(fn () => app(ContactDirectory::class)->addAddress(new Company(['name' => 'Unsaved']), ['type' => 'other', 'city' => 'Dhaka']))
        ->toThrow(InvalidArgumentException::class));

    $foreign = ($this->company)([], $this->globex);
    $this->inTenant($this->acme, fn () => expect(fn () => app(ContactDirectory::class)->addAddress($foreign, ['type' => 'other', 'city' => 'Dhaka']))
        ->toThrow(InvalidArgumentException::class));
});

it('audits each change on the parent with ids and the type only', function () {
    $company = ($this->company)(['name' => 'Northwind']);

    $this->actingAsMember($this->acme, $this->admin)
        ->post(($this->url)("/companies/{$company->id}/addresses"), [
            'type' => 'delivery',
            'name' => 'Rahim Uddin',
            'phone' => '+8801711000000',
            'email' => 'rahim@northwind.example',
            'address' => 'Secret Lane 7',
        ]);
    $address = $this->inTenant($this->acme, fn (): ContactAddress => ContactAddress::query()->sole());

    $this->actingAsMember($this->acme, $this->admin)
        ->put(($this->url)("/addresses/{$address->id}"), [
            'type' => 'delivery',
            'name' => 'Karim Mia',
            'phone' => '+8801711000000',
            'email' => 'rahim@northwind.example',
            'address' => 'Other Lane 9',
        ]);
    $this->actingAsMember($this->acme, $this->admin)
        ->delete(($this->url)("/addresses/{$address->id}"));

    $created = $this->auditTrail($this->acme, 'contact_address.created')->sole();
    $updated = $this->auditTrail($this->acme, 'contact_address.updated')->sole();
    $deleted = $this->auditTrail($this->acme, 'contact_address.deleted')->sole();

    expect($created->subject_type)->toBe('company')
        ->and($created->subject_id)->toBe($company->id)
        ->and($created->properties)->toEqual(['address_id' => $address->id, 'type' => 'delivery', 'is_default' => true])
        ->and($updated->properties['fields'])->toEqualCanonicalizing(['name', 'address'])
        ->and($deleted->properties['address_id'])->toBe($address->id);

    $trail = json_encode([$created->properties, $updated->properties, $deleted->properties], JSON_THROW_ON_ERROR);

    foreach (['Rahim', 'Karim', '8801711000000', 'rahim@', 'Secret Lane', 'Other Lane'] as $pii) {
        expect($trail)->not->toContain($pii);
    }
});

it('adds, changes and removes the addresses of a contact', function () {
    $contact = ($this->contact)(['first_name' => 'Nadia']);

    $this->actingAsMember($this->acme, $this->admin)
        ->post(($this->url)("/contacts/{$contact->id}/addresses"), ['type' => 'other', 'label' => 'Home', 'city' => 'Sylhet'])
        ->assertSessionHasNoErrors();

    $address = $this->inTenant($this->acme, fn (): ContactAddress => ContactAddress::query()->sole());

    expect($address->contact_id)->toBe($contact->id)
        ->and($address->company_id)->toBeNull()
        ->and($address->is_default)->toBeTrue()
        ->and($this->auditTrail($this->acme, 'contact_address.created')->sole()->subject_type)->toBe('contact');

    $this->actingAsMember($this->acme, $this->admin)
        ->put(($this->url)("/addresses/{$address->id}"), ['type' => 'other', 'label' => 'Home', 'city' => 'Khulna'])
        ->assertSessionHasNoErrors();

    expect(($this->fresh)($address)->city)->toBe('Khulna');
});

it('shows a company\'s addresses on its page, by type with the default first, and those of its people', function () {
    $company = ($this->company)(['name' => 'Northwind']);
    $other = ($this->address)($company, ['type' => 'other', 'label' => 'Showroom']);
    $deliveryOld = ($this->address)($company, ['type' => 'delivery', 'label' => 'Old warehouse']);
    $deliveryDefault = ($this->address)($company, ['type' => 'delivery', 'label' => 'New warehouse', 'is_default' => true]);
    $invoice = ($this->address)($company, ['type' => 'invoice', 'label' => 'Head office', 'country' => 'BD', 'postal_code' => '1212']);
    $person = ($this->contact)(['first_name' => 'Imran', 'company_id' => $company->id]);
    $home = ($this->address)($person, ['type' => 'other', 'label' => 'Home']);

    $this->actingAsMember($this->acme, $this->admin)
        ->get(($this->url)("/companies/{$company->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Contacts/CompanyShow')
            ->where('company.addresses', fn ($addresses): bool => collect($addresses)->pluck('id')->all() === [
                $invoice->id, $deliveryDefault->id, $deliveryOld->id, $other->id,
            ])
            ->where('company.addresses.0.type', 'invoice')
            ->where('company.addresses.0.label', 'Head office')
            ->where('company.addresses.0.postalCode', '1212')
            ->where('company.addresses.0.country', 'BD')
            ->where('company.addresses.0.isDefault', true)
            ->where('company.addresses.0.editable', true)
            ->where('company.addresses.2.isDefault', false)
            ->where('people.0.addresses.0.id', $home->id));

    // The people list carries the addresses for the details dialog; the companies list does not need them.
    $this->actingAsMember($this->acme, $this->admin)
        ->get(($this->url)('/contacts'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contacts.data.0.addresses.0.label', 'Home'));

    $this->actingAsMember($this->acme, $this->admin)
        ->get(($this->url)('/companies'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('companies.data.0.addresses'));
});

it('shows the addresses as read only to someone who may only look, and refuses their changes', function () {
    $company = ($this->company)();
    $address = ($this->address)($company);
    $viewer = ($this->role)('Readers', ['core.contacts.view'], ['core.contacts.view' => 'all']);

    $this->actingAsMember($this->acme, $viewer)
        ->get(($this->url)("/companies/{$company->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('company.addresses.0.editable', false));

    $this->actingAsMember($this->acme, $viewer)->post(($this->url)("/companies/{$company->id}/addresses"), ['type' => 'invoice', 'city' => 'Dhaka'])->assertForbidden();
    $this->actingAsMember($this->acme, $viewer)->put(($this->url)("/addresses/{$address->id}"), ['type' => 'invoice', 'city' => 'Dhaka'])->assertForbidden();
    $this->actingAsMember($this->acme, $viewer)->post(($this->url)("/addresses/{$address->id}/default"))->assertForbidden();
    $this->actingAsMember($this->acme, $viewer)->delete(($this->url)("/addresses/{$address->id}"))->assertForbidden();
});

it('answers 404 for the addresses of a record outside the member\'s scope', function () {
    $lead = ($this->role)('Own only', ['core.contacts.view', 'core.contacts.manage'], ['core.contacts.view' => 'own', 'core.contacts.manage' => 'own']);
    $mine = ($this->company)(['name' => 'Mine'], null, $lead);
    $theirs = ($this->company)(['name' => 'Theirs'], null, $this->admin);
    $theirAddress = ($this->address)($theirs);
    $theirPerson = $this->inTenant($this->acme, function (): Contact {
        $contact = Contact::factory()->make();
        $contact->assignOwner($this->membershipOf($this->acme, $this->admin));
        $contact->save();

        return $contact;
    });

    $this->actingAsMember($this->acme, $lead)
        ->post(($this->url)("/companies/{$mine->id}/addresses"), ['type' => 'invoice', 'city' => 'Dhaka'])
        ->assertSessionHasNoErrors();

    $this->actingAsMember($this->acme, $lead)->post(($this->url)("/companies/{$theirs->id}/addresses"), ['type' => 'invoice', 'city' => 'Dhaka'])->assertNotFound();
    $this->actingAsMember($this->acme, $lead)->post(($this->url)("/contacts/{$theirPerson->id}/addresses"), ['type' => 'other', 'city' => 'Dhaka'])->assertNotFound();
    $this->actingAsMember($this->acme, $lead)->put(($this->url)("/addresses/{$theirAddress->id}"), ['type' => 'invoice', 'city' => 'Rajshahi'])->assertNotFound();
    $this->actingAsMember($this->acme, $lead)->post(($this->url)("/addresses/{$theirAddress->id}/default"))->assertNotFound();
    $this->actingAsMember($this->acme, $lead)->delete(($this->url)("/addresses/{$theirAddress->id}"))->assertNotFound();

    expect(($this->fresh)($theirAddress)->trashed())->toBeFalse();
});

it('hides the addresses of a deleted company with it', function () {
    $company = ($this->company)();
    $address = ($this->address)($company);
    $this->inTenant($this->acme, fn () => app(ContactDirectory::class)->deleteCompany($company));

    $this->actingAsMember($this->acme, $this->admin)->put(($this->url)("/addresses/{$address->id}"), ['type' => 'invoice', 'city' => 'Dhaka'])->assertNotFound();
    $this->actingAsMember($this->acme, $this->admin)->delete(($this->url)("/addresses/{$address->id}"))->assertNotFound();

    // Kept with the soft-deleted company, so restoring it brings them back.
    expect(($this->fresh)($address)->trashed())->toBeFalse();
});

it('moves the duplicate\'s addresses to the kept contact when contacts are merged', function () {
    $canonical = ($this->contact)(['first_name' => 'Nadia', 'email' => 'nadia@example.test']);
    $duplicate = ($this->contact)(['first_name' => 'Nadiya', 'email' => 'nadiya@example.test']);
    $keptInvoice = ($this->address)($canonical, ['type' => 'invoice', 'label' => 'Kept invoice']);
    $movedInvoice = ($this->address)($duplicate, ['type' => 'invoice', 'label' => 'Moved invoice']);
    $movedDelivery = ($this->address)($duplicate, ['type' => 'delivery', 'label' => 'Moved delivery']);
    $foreign = ($this->address)(($this->contact)([], $this->globex), [], $this->globex);

    $this->actingAsOwner($this->acme)
        ->post(($this->url)("/contacts/{$canonical->id}/merge"), ['duplicate_id' => $duplicate->id, 'fields' => []])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $moved = [($this->fresh)($movedInvoice), ($this->fresh)($movedDelivery)];

    expect($moved[0]->contact_id)->toBe($canonical->id)
        ->and($moved[1]->contact_id)->toBe($canonical->id)
        // The kept contact already had an invoice default; the delivery address stays the default of its type.
        ->and($moved[0]->is_default)->toBeFalse()
        ->and($moved[1]->is_default)->toBeTrue()
        ->and(($this->fresh)($keptInvoice)->is_default)->toBeTrue()
        ->and($this->auditTrail($this->acme, 'contact.merged')->sole()->properties['moved_references']['contact_addresses'])->toBe(2)
        ->and($this->inTenant($this->globex, fn () => ContactAddress::query()->findOrFail($foreign->id)->contact_id))->toBe($foreign->contact_id);
});

it('answers 404 for another workspace\'s records on every address route', function () {
    $company = ($this->company)();
    $contact = ($this->contact)();
    $address = ($this->address)($company);
    $data = ['type' => 'invoice', 'city' => 'Dhaka'];

    $this->assertNotFoundFromOtherTenant('POST', "/companies/{$company->id}/addresses", $this->acme, $data);
    $this->assertNotFoundFromOtherTenant('POST', "/contacts/{$contact->id}/addresses", $this->acme, $data);
    $this->assertNotFoundFromOtherTenant('PUT', "/addresses/{$address->id}", $this->acme, $data);
    $this->assertNotFoundFromOtherTenant('POST', "/addresses/{$address->id}/default", $this->acme);
    $this->assertNotFoundFromOtherTenant('DELETE', "/addresses/{$address->id}", $this->acme);
});
