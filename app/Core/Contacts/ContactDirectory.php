<?php

declare(strict_types=1);

namespace App\Core\Contacts;

use App\Core\Contacts\Events\CompanyCreated;
use App\Core\Contacts\Events\CompanyDeleted;
use App\Core\Contacts\Events\CompanyUpdated;
use App\Core\Contacts\Events\ContactCreated;
use App\Core\Contacts\Events\ContactDeleted;
use App\Core\Contacts\Events\ContactUpdated;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use Illuminate\Support\Facades\DB;

/**
 * Every write to the shared contacts and companies of the active workspace.
 *
 * Modules that create or change a contact go through here as well, so the
 * audit entry (the models are Auditable) and the domain event always follow.
 * `$owner` is only applied when given: `false` leaves the owner as it is.
 */
final class ContactDirectory
{
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
}
