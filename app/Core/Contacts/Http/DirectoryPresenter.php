<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Models\ContactAddress;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * How the contact directory's pages show a contact or a company, and the choices their forms offer. One place, so the
 * people list, the companies list and a company's own page agree on every field and on what the viewer may change.
 */
final class DirectoryPresenter
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    /**
     * A contact as a row or card. Expects `company:id,name` and `owner.user:id,name` loaded; carries `addresses` only
     * when they were loaded too.
     *
     * @return array<string, mixed>
     */
    public function contact(Contact $contact, User $viewer): array
    {
        $editable = $this->records->allows($viewer, ContactController::MANAGE, $contact);

        return [
            'id' => $contact->id,
            'name' => $contact->fullName(),
            'firstName' => $contact->first_name,
            'lastName' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'jobTitle' => $contact->job_title,
            'address' => $contact->address,
            'city' => $contact->city,
            'country' => $contact->country,
            'notes' => $contact->notes,
            'company' => $contact->company?->only(['id', 'name']),
            'owner' => $contact->owner === null ? null : [
                'id' => $contact->owner->id,
                'name' => $contact->owner->user->name,
            ],
            // Hide what the viewer's scope does not reach; the server checks again on every change.
            'editable' => $editable,
            'deletable' => $this->records->allows($viewer, ContactController::DELETE, $contact),
            'createdAt' => $contact->created_at->toIso8601String(),
            ...($contact->relationLoaded('addresses') ? ['addresses' => $this->addresses($contact->addresses, $editable)] : []),
        ];
    }

    /**
     * A company as a row or page. Expects `owner.user:id,name` loaded; `$people` is how many of its contacts the viewer
     * can see. Carries `addresses` only when they were loaded (its own page).
     *
     * @return array<string, mixed>
     */
    public function company(Company $company, User $viewer, int $people): array
    {
        $editable = $this->records->allows($viewer, ContactController::MANAGE, $company);

        return [
            'id' => $company->id,
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'industry' => $company->industry,
            'taxId' => $company->tax_id,
            'address' => $company->address,
            'city' => $company->city,
            'country' => $company->country,
            'notes' => $company->notes,
            'contacts' => $people,
            'owner' => $company->owner === null ? null : [
                'id' => $company->owner->id,
                'name' => $company->owner->user->name,
            ],
            'editable' => $editable,
            'deletable' => $this->records->allows($viewer, ContactController::DELETE, $company),
            'createdAt' => $company->created_at->toIso8601String(),
            ...($company->relationLoaded('addresses') ? ['addresses' => $this->addresses($company->addresses, $editable)] : []),
        ];
    }

    /**
     * A parent's typed addresses: invoice, then delivery, then other; the default of each type first, then the oldest.
     * `$editable` is whether the viewer may change the parent, which decides the addresses too.
     *
     * @param  Collection<int, ContactAddress>  $addresses
     * @return list<array<string, mixed>>
     */
    public function addresses(Collection $addresses, bool $editable): array
    {
        return $addresses
            ->sortBy([
                static fn (ContactAddress $a, ContactAddress $b): int => $a->type->rank() <=> $b->type->rank(),
                static fn (ContactAddress $a, ContactAddress $b): int => $b->is_default <=> $a->is_default,
                static fn (ContactAddress $a, ContactAddress $b): int => [$a->created_at->getTimestamp(), $a->id] <=> [$b->created_at->getTimestamp(), $b->id],
            ])
            ->map(static fn (ContactAddress $address): array => [
                'id' => $address->id,
                'type' => $address->type->value,
                'label' => $address->label,
                'name' => $address->name,
                'email' => $address->email,
                'phone' => $address->phone,
                'address' => $address->address,
                'city' => $address->city,
                'postalCode' => $address->postal_code,
                'country' => $address->country,
                'isDefault' => $address->is_default,
                'editable' => $editable,
            ])
            ->values()
            ->all();
    }

    /**
     * Companies a contact can be put in. Empty unless the viewer may add or change contacts.
     *
     * @return list<array{id: string, name: string}>
     */
    public function companyOptions(User $viewer): array
    {
        if (! $viewer->can(ContactController::MANAGE)) {
            return [];
        }

        return $this->records->constrain(Company::query(), $viewer, ContactController::VIEW)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name])
            ->values()
            ->all();
    }

    /**
     * Members a contact or company can be handed to. Empty unless the viewer manages every record.
     *
     * @return list<array{id: string, name: string}>
     */
    public function ownerOptions(User $viewer): array
    {
        if ($this->access->scopeFor($viewer, ContactController::MANAGE) !== AccessScope::All) {
            return [];
        }

        return TenantMembership::query()
            ->where('status', MembershipStatus::Active)
            ->with('user:id,name')
            ->get()
            ->map(fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
