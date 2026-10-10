<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Company;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisations in the workspace's shared contact directory. Governed by
 * the same three permissions as contacts.
 */
final class CompanyController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly ContactDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $companies = $this->records->constrain(Company::query(), $actor, ContactController::VIEW)
            ->with('owner.user:id,name')
            // Only the people the viewer can see: the count links to that list,
            // and a contact outside someone's scope is not disclosed to exist.
            ->withCount(['contacts' => function (Builder $contacts) use ($actor): void {
                $this->records->constrain($contacts, $actor, ContactController::VIEW);
            }])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search))
                ->orWhere('phone', 'like', Like::contains($search))))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Company $company): array => [
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
                'contacts' => (int) $company->getAttribute('contacts_count'),
                'owner' => $company->owner === null ? null : [
                    'id' => $company->owner->id,
                    'name' => $company->owner->user->name,
                ],
                'editable' => $this->records->allows($actor, ContactController::MANAGE, $company),
                'deletable' => $this->records->allows($actor, ContactController::DELETE, $company),
                'createdAt' => $company->created_at->toIso8601String(),
            ]);

        return Inertia::render('Core/Pages/Contacts/Companies', [
            'companies' => $companies,
            'filters' => ['search' => $search],
            'owners' => $this->ownerOptions($actor),
            'canCreate' => $actor->can(ContactController::MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $input = $this->validated($request, $actor);

        $company = $this->directory->createCompany($input['attributes'], $input['owner']);

        Toast::success(__('Company ":name" added.', ['name' => $company->name]));

        return back();
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $this->records->authorize($actor, ContactController::MANAGE, $company);

        $input = $this->validated($request, $actor, $company);

        $this->directory->updateCompany($company, $input['attributes'], $input['owner']);

        Toast::success(__('Company ":name" saved.', ['name' => $company->name]));

        return back();
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $this->records->authorize($actor, ContactController::DELETE, $company);

        $this->directory->deleteCompany($company);

        Toast::success(__('Company deleted.'));

        return back();
    }

    /**
     * @return array{attributes: array<string, mixed>, owner: TenantMembership|false|null}
     */
    private function validated(Request $request, User $actor, ?Company $company = null): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'industry' => ['nullable', 'string', 'max:120'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $attributes = Arr::except($input, ['owner_id']);

        if (isset($attributes['email'])) {
            $attributes['email'] = mb_strtolower($attributes['email']);
        }

        // Only someone who manages every company may hand one to another member.
        // Sending the owner it already has hands nothing over, so that owner need
        // not be someone a company could be given to now (suspended since, say).
        $owner = false;

        if (array_key_exists('owner_id', $input)
            && $this->access->scopeFor($actor, ContactController::MANAGE) === AccessScope::All
            && ($company === null || $input['owner_id'] !== $company->owner_id)) {
            $owner = $input['owner_id'] === null
                ? null
                : TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
        }

        return ['attributes' => $attributes, 'owner' => $owner];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function ownerOptions(User $actor): array
    {
        if ($this->access->scopeFor($actor, ContactController::MANAGE) !== AccessScope::All) {
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
