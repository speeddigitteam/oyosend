<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use Illuminate\Http\Request;

final class CrmControllerSupport
{
    public function __construct(private readonly AccessChecker $access, private readonly RecordAccess $records) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /** @param array<string, mixed> $input */
    public function ownerForCreate(array $input, User $actor, string $assignPermission): TenantMembership
    {
        if (! array_key_exists('owner_id', $input) || $input['owner_id'] === null || $this->access->scopeFor($actor, $assignPermission) !== AccessScope::All) {
            return $this->membership($actor);
        }

        return $this->activeOwner($input['owner_id']);
    }

    public function assignmentTarget(mixed $ownerId, User $actor, string $permission): ?TenantMembership
    {
        $membership = $this->membership($actor);

        if ($ownerId === $membership->id) {
            return $membership;
        }

        abort_unless($this->access->scopeFor($actor, $permission) === AccessScope::All, 403);

        return $ownerId === null ? null : $this->activeOwner($ownerId);
    }

    public function contact(?string $id, User $actor): ?Contact
    {
        return $id === null ? null : $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->findOrFail($id);
    }

    public function company(?string $id, User $actor): ?Company
    {
        return $id === null ? null : $this->records->constrain(Company::query(), $actor, ContactController::VIEW)->findOrFail($id);
    }

    /** @return list<array{id: string, name: string}> */
    public function contactOptions(User $actor): array
    {
        return $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'last_name'])
            ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName()])->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function companyOptions(User $actor): array
    {
        return $this->records->constrain(Company::query(), $actor, ContactController::VIEW)
            ->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name])->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function leadOptions(User $actor): array
    {
        return $this->records->constrain(Lead::query(), $actor, LeadController::VIEW)
            ->latest()->limit(500)->get(['id', 'title'])->map(fn (Lead $lead): array => ['id' => $lead->id, 'name' => $lead->title])->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function dealOptions(User $actor): array
    {
        return $this->records->constrain(Deal::query(), $actor, DealController::VIEW)
            ->latest()->limit(500)->get(['id', 'title'])->map(fn (Deal $deal): array => ['id' => $deal->id, 'name' => $deal->title])->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function ownerOptions(User $actor, string $permission): array
    {
        if ($this->access->scopeFor($actor, $permission) !== AccessScope::All) {
            return [];
        }

        return TenantMembership::query()->where('status', MembershipStatus::Active)->with('user:id,name')->get()
            ->map(fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name])
            ->sortBy('name')->values()->all();
    }

    private function activeOwner(mixed $id): TenantMembership
    {
        abort_unless(is_string($id), 404);

        return TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($id);
    }
}
