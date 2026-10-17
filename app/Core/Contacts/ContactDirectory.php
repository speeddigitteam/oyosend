<?php

declare(strict_types=1);

namespace App\Core\Contacts;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Enums\AddressType;
use App\Core\Contacts\Events\CompanyCreated;
use App\Core\Contacts\Events\CompanyDeleted;
use App\Core\Contacts\Events\CompanyUpdated;
use App\Core\Contacts\Events\ContactCreated;
use App\Core\Contacts\Events\ContactDeleted;
use App\Core\Contacts\Events\ContactUpdated;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Models\ContactAddress;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every write to the shared contacts and companies of the active workspace.
 *
 * Modules that create or change a contact go through here as well, so the
 * audit entry (the models are Auditable) and the domain event always follow.
 * `$owner` is only applied when given: `false` leaves the owner as it is.
 *
 * The typed addresses of a company or a contact (ADR 0039) are written here too. Each belongs to exactly one parent,
 * and every type a parent has addresses of has exactly one default: the first of a type becomes it, making another
 * the default takes it over, and removing the default hands it to the oldest that is left. Their changes are audited
 * on the parent, with ids and the type only: no address text reaches the trail.
 */
final class ContactDirectory
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createContact(array $attributes, TenantMembership|false|null $owner = false): Contact
    {
        return DB::transaction(function () use ($attributes, $owner): Contact {
            $contact = new Contact($attributes);

            if ($owner !== false) {
                $contact->assignOwner($owner);
            }

            $contact->save();

            ContactCreated::dispatch($contact);

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateContact(Contact $contact, array $attributes, TenantMembership|false|null $owner = false): Contact
    {
        return DB::transaction(function () use ($contact, $attributes, $owner): Contact {
            $contact->fill($attributes);

            if ($owner !== false) {
                $contact->assignOwner($owner);
            }

            if ($contact->isDirty()) {
                $contact->save();

                ContactUpdated::dispatch($contact);
            }

            return $contact;
        });
    }

    public function deleteContact(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $contact->delete();

            ContactDeleted::dispatch($contact);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCompany(array $attributes, TenantMembership|false|null $owner = false): Company
    {
        return DB::transaction(function () use ($attributes, $owner): Company {
            $company = new Company($attributes);

            if ($owner !== false) {
                $company->assignOwner($owner);
            }

            $company->save();

            CompanyCreated::dispatch($company);

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCompany(Company $company, array $attributes, TenantMembership|false|null $owner = false): Company
    {
        return DB::transaction(function () use ($company, $attributes, $owner): Company {
            $company->fill($attributes);

            if ($owner !== false) {
                $company->assignOwner($owner);
            }

            if ($company->isDirty()) {
                $company->save();

                CompanyUpdated::dispatch($company);
            }

            return $company;
        });
    }

    /**
     * The company's people stay in the directory, without a company.
     */
    public function deleteCompany(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            // The foreign key only clears `company_id` on a hard delete.
            Contact::query()->where('company_id', $company->getKey())->update(['company_id' => null]);

            $company->delete();

            CompanyDeleted::dispatch($company);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  {@see ContactAddress::FIELDS}, and `is_default` to make it the default of its type.
     */
    public function addAddress(Company|Contact $parent, array $attributes): ContactAddress
    {
        $this->assertInWorkspace($parent);

        return DB::transaction(function () use ($parent, $attributes): ContactAddress {
            $this->lock($parent);

            $address = new ContactAddress(Arr::only($attributes, ContactAddress::FIELDS));
            $address->setAttribute($parent instanceof Company ? 'company_id' : 'contact_id', $parent->getKey());
            $this->assertOneParent($address);

            $first = ! $this->ofTypeOf($address, $address->type)->exists();
            $address->is_default = $first || ($attributes['is_default'] ?? false) === true;

            if ($address->is_default) {
                $this->ofTypeOf($address, $address->type)->where('is_default', true)->update(['is_default' => false]);
            }

            $address->save();

            $this->audit->record('contact_address.created', $parent, $this->auditEntry($address));

            return $address;
        });
    }

    /**
     * A change of type moves the address to that type's group: the default it was hands over to the oldest left behind,
     * and it becomes the default of the new type only when it is the first there or `is_default` says so. Turning the
     * default off is not a change on its own; another address of the type is made the default instead.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateAddress(ContactAddress $address, array $attributes): ContactAddress
    {
        return DB::transaction(function () use ($address, $attributes): ContactAddress {
            $parent = $this->parentOf($address);
            $this->lock($parent);
            $this->reread($address);

            $previousType = $address->type;
            $wasDefault = $address->is_default;

            $address->fill(Arr::only($attributes, ContactAddress::FIELDS));
            $this->assertOneParent($address);

            $typeChanged = $address->type !== $previousType;
            $wantsDefault = ($attributes['is_default'] ?? false) === true;

            if ($typeChanged) {
                $address->is_default = $wantsDefault || ! $this->ofTypeOf($address, $address->type)->exists();
            } elseif ($wantsDefault) {
                $address->is_default = true;
            }

            if ($address->is_default && ($typeChanged || ! $wasDefault)) {
                $this->ofTypeOf($address, $address->type)->where('is_default', true)->update(['is_default' => false]);
            }

            if (! $address->isDirty()) {
                return $address;
            }

            $fields = array_values(array_diff(array_keys($address->getDirty()), ['is_default', 'updated_at']));
            $address->save();

            if ($typeChanged && $wasDefault) {
                $this->promoteDefault($address, $previousType);
            }

            $this->audit->record('contact_address.updated', $parent, [
                ...$this->auditEntry($address),
                'previous_type' => $typeChanged ? $previousType->value : null,
                'fields' => $fields,
            ]);

            return $address;
        });
    }

    public function makeDefaultAddress(ContactAddress $address): ContactAddress
    {
        // Decided under the lock: a copy read before it may be stale.
        return $this->updateAddress($address, ['is_default' => true]);
    }

    public function deleteAddress(ContactAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $parent = $this->parentOf($address);
            $this->lock($parent);
            $this->reread($address);

            $address->delete();

            if ($address->is_default) {
                $this->promoteDefault($address, $address->type);
            }

            $this->audit->record('contact_address.deleted', $parent, $this->auditEntry($address));
        });
    }

    /**
     * Moves every address of a duplicate contact to the one it is merged into. Call inside the merge transaction. A
     * moved address stays the default of its type only where the kept contact had no address of that type.
     *
     * @return int How many addresses moved.
     */
    public function moveAddresses(Contact $from, Contact $to): int
    {
        $this->assertInWorkspace($from);
        $this->assertInWorkspace($to);

        $taken = ContactAddress::query()->where('contact_id', $to->getKey())->get(['type'])
            ->map(static fn (ContactAddress $address): string => $address->type->value)
            ->unique()
            ->values()
            ->all();

        if ($taken !== []) {
            ContactAddress::query()->where('contact_id', $from->getKey())->whereIn('type', $taken)->update(['is_default' => false]);
        }

        return ContactAddress::query()->where('contact_id', $from->getKey())->update(['contact_id' => $to->getKey()]);
    }

    /**
     * Ids and the type only: no address, name, phone or email goes into the trail.
     *
     * @return array<string, mixed>
     */
    private function auditEntry(ContactAddress $address): array
    {
        return [
            'address_id' => $address->id,
            'type' => $address->type->value,
            'is_default' => $address->is_default,
        ];
    }

    private function parentOf(ContactAddress $address): Company|Contact
    {
        $parent = $address->company_id !== null
            ? Company::query()->find($address->company_id)
            : Contact::query()->find($address->contact_id);

        if ($parent === null) {
            throw new InvalidArgumentException('The address has no live company or contact.');
        }

        return $parent;
    }

    private function assertInWorkspace(Company|Contact $parent): void
    {
        if (! $parent->exists || $parent->tenant_id !== $this->context->requireId()) {
            throw new InvalidArgumentException('An address can only be given to a saved record of the active workspace.');
        }
    }

    private function assertOneParent(ContactAddress $address): void
    {
        if (($address->company_id === null) === ($address->contact_id === null)) {
            throw new InvalidArgumentException('An address belongs to exactly one company or one contact.');
        }
    }

    /**
     * Reads the address again once its parent is locked: the copy bound to the request was read before the lock, and a
     * request that held it may have moved the default or deleted the address since.
     */
    private function reread(ContactAddress $address): void
    {
        $address->refresh();

        if ($address->trashed()) {
            throw (new ModelNotFoundException)->setModel(ContactAddress::class, [$address->getKey()]);
        }
    }

    /** Serialises the default bookkeeping of one parent's addresses. */
    private function lock(Company|Contact $parent): void
    {
        $parent->newQuery()->whereKey($parent->getKey())->lockForUpdate()->first();
    }

    /**
     * The other live addresses of the same parent and the given type.
     *
     * @return Builder<ContactAddress>
     */
    private function ofTypeOf(ContactAddress $address, AddressType $type): Builder
    {
        return ContactAddress::query()
            ->where($address->company_id !== null ? 'company_id' : 'contact_id', $address->company_id ?? $address->contact_id)
            ->where('type', $type->value)
            ->when($address->exists, static fn (Builder $query) => $query->whereKeyNot($address->getKey()));
    }

    /** Makes the oldest address left of a type its default, unless one already is. */
    private function promoteDefault(ContactAddress $removed, AddressType $type): void
    {
        $remaining = $this->ofTypeOf($removed, $type);

        if ((clone $remaining)->where('is_default', true)->exists()) {
            return;
        }

        $next = $remaining->orderBy('created_at')->orderBy('id')->first();

        if ($next !== null) {
            $next->is_default = true;
            $next->save();
        }
    }
}
