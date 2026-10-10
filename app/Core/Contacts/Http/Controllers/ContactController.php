<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people in the workspace's shared contact directory.
 *
 * The route names the permission; {@see RecordAccess} then narrows it to the
 * scope it was granted with (own, department, branch or every contact).
 */
final class ContactController
{
    public const string VIEW = 'core.contacts.view';

    public const string MANAGE = 'core.contacts.manage';

    public const string DELETE = 'core.contacts.delete';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly ContactDirectory $directory,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'size:26'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $company = isset($filters['company'])
            ? $this->records->constrain(Company::query(), $actor, self::VIEW)->find($filters['company'])
            : null;

        $contacts = $this->records->constrain(Contact::query(), $actor, self::VIEW)
            ->with(['company:id,name', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('first_name', 'like', Like::contains($search))
                ->orWhere('last_name', 'like', Like::contains($search))
                // The name as the list shows it: "Imran Hossain" is in neither column on its own.
                ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [Like::contains($search)])
                ->orWhere('email', 'like', Like::contains($search))
                ->orWhere('phone', 'like', Like::contains($search))))
            ->when($company !== null, fn ($query) => $query->where('company_id', $company?->getKey()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $contact): array => [
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
                'editable' => $this->records->allows($actor, self::MANAGE, $contact),
                'deletable' => $this->records->allows($actor, self::DELETE, $contact),
                'createdAt' => $contact->created_at->toIso8601String(),
            ]);

        return Inertia::render('Core/Pages/Contacts/Index', [
            'contacts' => $contacts,
            'filters' => [
                'search' => $search,
                'company' => $company?->only(['id', 'name']),
            ],
            'companies' => $this->companyOptions($actor),
            'owners' => $this->ownerOptions($actor),
            'canCreate' => $actor->can(self::MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $input = $this->validated($request, $actor);

        $contact = $this->directory->createContact($input['attributes'], $input['owner']);

        Toast::success(__('Contact ":name" added.', ['name' => $contact->fullName()]));

        return back();
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $this->records->authorize($actor, self::MANAGE, $contact);

        $input = $this->validated($request, $actor, $contact);

        $this->directory->updateContact($contact, $input['attributes'], $input['owner']);

        Toast::success(__('Contact ":name" saved.', ['name' => $contact->fullName()]));

        return back();
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $this->records->authorize($actor, self::DELETE, $contact);

        $this->directory->deleteContact($contact);

        Toast::success(__('Contact deleted.'));

        return back();
    }

    /**
     * @return array{attributes: array<string, mixed>, owner: TenantMembership|false|null}
     */
    private function validated(Request $request, User $actor, ?Contact $contact = null): array
    {
        $tenantId = $this->context->requireId();

        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => [
                'nullable', 'string', 'email:rfc', 'max:255',
                // One live contact per address in this workspace.
                Rule::unique('contacts', 'email')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($contact?->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'company_id' => ['nullable', 'string', 'size:26'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $attributes = Arr::except($input, ['owner_id']);

        if (isset($attributes['email'])) {
            $attributes['email'] = mb_strtolower($attributes['email']);
        }

        // Leaving a contact in the company it is already in asks for nothing new,
        // even when someone else put it in one the person cannot see.
        if (($attributes['company_id'] ?? null) !== null && $attributes['company_id'] !== $contact?->company_id) {
            // A company the person can see - never one outside their scope or workspace.
            $attributes['company_id'] = $this->records->constrain(Company::query(), $actor, self::VIEW)
                ->findOrFail($attributes['company_id'])
                ->getKey();
        }

        return [
            'attributes' => $attributes,
            'owner' => $this->requestedOwner($input, $actor, $contact),
        ];
    }

    /**
     * Only someone who manages every contact may hand one to another member.
     * For everyone else the owner is left alone (a new contact becomes theirs).
     *
     * @param  array<string, mixed>  $input
     */
    private function requestedOwner(array $input, User $actor, ?Contact $contact = null): TenantMembership|false|null
    {
        if (! array_key_exists('owner_id', $input) || $this->access->scopeFor($actor, self::MANAGE) !== AccessScope::All) {
            return false;
        }

        // The owner it already has: nothing is being handed over, so they need
        // not be someone a contact could be given to now (suspended since, say).
        if ($contact !== null && $input['owner_id'] === $contact->owner_id) {
            return false;
        }

        return $input['owner_id'] === null
            ? null
            : TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
    }

    /**
     * Companies the viewer can attach a contact to.
     *
     * @return list<array{id: string, name: string}>
     */
    private function companyOptions(User $actor): array
    {
        if (! $actor->can(self::MANAGE)) {
            return [];
        }

        return $this->records->constrain(Company::query(), $actor, self::VIEW)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name])
            ->all();
    }

    /**
     * Members a contact can be handed to. Empty unless the viewer may reassign.
     *
     * @return list<array{id: string, name: string}>
     */
    private function ownerOptions(User $actor): array
    {
        if ($this->access->scopeFor($actor, self::MANAGE) !== AccessScope::All) {
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
