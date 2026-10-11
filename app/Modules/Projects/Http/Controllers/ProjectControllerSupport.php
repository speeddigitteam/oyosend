<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;

final class ProjectControllerSupport
{
    public function __construct(private readonly AccessChecker $access, private readonly RecordAccess $records, private readonly TenantContext $context) {}

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
    public function ownerForCreate(array $input, User $actor): TenantMembership
    {
        if (! array_key_exists('owner_id', $input) || $input['owner_id'] === null || $this->access->scopeFor($actor, ProjectController::EDIT) !== AccessScope::All) {
            return $this->membership($actor);
        }

        return $this->activeMember($input['owner_id']);
    }

    public function activeMember(mixed $id): TenantMembership
    {
        abort_unless(is_string($id), 404);

        return TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($id);
    }

    public function taskOwner(mixed $id, User $actor): TenantMembership
    {
        $actorMembership = $this->membership($actor);

        if ($id === null || $id === '' || $id === $actorMembership->id) {
            return $actorMembership;
        }

        abort_unless($this->access->scopeFor($actor, TaskController::ASSIGN) === AccessScope::All, 403);

        return $this->activeMember($id);
    }

    /** @param list<string> $membershipIds */
    public function guardTaskAssignments(array $membershipIds, User $actor): void
    {
        $self = $this->membership($actor)->id;
        $other = array_filter($membershipIds, static fn (string $id): bool => $id !== $self);
        abort_if($other !== [] && $this->access->scopeFor($actor, TaskController::ASSIGN) !== AccessScope::All, 403);
    }

    /** @return list<array{id: string, name: string}> */
    public function taskAssigneeOptions(User $actor): array
    {
        if (! $actor->can(TaskController::ASSIGN)) {
            return [];
        }

        if ($this->access->scopeFor($actor, TaskController::ASSIGN) === AccessScope::All && $actor->can('core.members.view')) {
            return $this->memberOptions($actor);
        }

        $membership = $this->membership($actor);
        $membership->loadMissing('user:id,name');

        return [['id' => $membership->id, 'name' => $membership->user->name]];
    }

    /** @return list<array{id: string, name: string}> */
    public function memberOptions(User $actor): array
    {
        if (! $actor->can('core.members.view')) {
            return [];
        }

        return TenantMembership::query()->where('status', MembershipStatus::Active)->with('user:id,name')->get()
            ->map(fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name])
            ->sortBy('name')->values()->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function approvalMemberOptions(): array
    {
        $model = new TenantMembership;
        $approverUserIds = $model->getConnection()->table('model_has_roles as assignment')
            ->join('roles', 'roles.id', '=', 'assignment.role_id')
            ->join('role_has_permissions as grant', 'grant.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'grant.permission_id')
            ->where('assignment.tenant_id', $this->context->requireId())
            ->where('assignment.model_type', (new User)->getMorphClass())
            ->where('roles.tenant_id', $this->context->requireId())
            ->where('permissions.guard_name', 'web')
            ->where('permissions.name', 'projects.approvals.decide')
            ->distinct()
            ->pluck('assignment.model_id');

        return TenantMembership::query()->where('status', MembershipStatus::Active)
            ->where(fn ($query) => $query->where('is_owner', true)->orWhereIn('user_id', $approverUserIds))
            ->with('user:id,name')->get()
            ->map(fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name])
            ->sortBy('name')->values()->all();
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
        return $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'last_name'])
            ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName()])->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function companyOptions(User $actor): array
    {
        return $this->records->constrain(Company::query(), $actor, ContactController::VIEW)->orderBy('name')->limit(500)->get(['id', 'name'])
            ->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name])->all();
    }
}
