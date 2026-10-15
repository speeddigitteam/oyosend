<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;

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
     * A contact as a row or card. Expects `company:id,name` and `owner.user:id,name` loaded.
     *
     * @return array<string, mixed>
     */
    public function contact(Contact $contact, User $viewer): array
    {
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
            'editable' => $this->records->allows($viewer, ContactController::MANAGE, $contact),
            'deletable' => $this->records->allows($viewer, ContactController::DELETE, $contact),
            'createdAt' => $contact->created_at->toIso8601String(),
        ];
    }

    /**
     * A company as a row or page. Expects `owner.user:id,name` loaded; `$people` is how many of its contacts the viewer
     * can see.
     *
     * @return array<string, mixed>
     */
    public function company(Company $company, User $viewer, int $people): array
    {
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
            'editable' => $this->records->allows($viewer, ContactController::MANAGE, $company),
            'deletable' => $this->records->allows($viewer, ContactController::DELETE, $company),
            'createdAt' => $company->created_at->toIso8601String(),
        ];
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
