<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Team;

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Actions\MemberManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class MemberController
{
    public function index(Request $request, RoleManager $roles, MemberManager $manager): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $managesMembers = $actor->can('core.members.manage');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $members = TenantMembership::query()
            ->with(['user:id,name,email,two_factor_confirmed_at,last_login_at', 'user.roles', 'branch:id,name', 'department:id,name'])
            ->when($search !== '', fn ($query) => $query->whereHas(
                'user',
                fn ($user) => $user->where(fn ($match) => $match
                    ->where('name', 'like', Like::contains($search))
                    ->orWhere('email', 'like', Like::contains($search))),
            ))
            ->orderByDesc('is_owner')
            ->orderBy('joined_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TenantMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'initials' => $membership->user->initials(),
                'role' => $membership->user->roles->first()?->only(['id', 'name']),
                'branch' => $membership->branch?->only(['id', 'name']),
                'department' => $membership->department?->only(['id', 'name']),
                'status' => $membership->status->value,
                'isOwner' => $membership->is_owner,
                'isSelf' => $membership->user_id === $actor->id,
                // Whether this viewer gets an actions menu on the row; the same rule is enforced on every change.
                'manageable' => $managesMembers && $manager->canManage($actor, $membership),
                // The owner is the one person who may set their own branch and department.
                'placeable' => $managesMembers && ($manager->canManage($actor, $membership)
                    || ($membership->is_owner && $membership->user_id === $actor->id)),
                'twoFactorEnabled' => $membership->user->two_factor_confirmed_at !== null,
                'joinedAt' => $membership->joined_at?->toIso8601String(),
                'lastSeenAt' => $membership->last_seen_at?->toIso8601String(),
            ]);

        $invitations = $actor->can('core.members.invite')
            ? Invitation::query()
                ->open()
                ->with(['role:id,name', 'inviter:id,name'])
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->name,
                    'inviter' => $invitation->inviter?->name,
                    'expired' => $invitation->hasExpired(),
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                    'createdAt' => $invitation->created_at->toIso8601String(),
                ])
                ->all()
            : [];

        return Inertia::render('Core/Pages/Team/Members', [
            'members' => $members,
            'invitations' => $invitations,
            'filters' => ['search' => $search],
            // Only roles this person is allowed to hand out.
            'assignableRoles' => Role::query()
                ->forCurrentTenant()
                ->orderBy('name')
                ->get()
                ->filter(fn (Role $role): bool => $roles->canAssign($actor, $role))
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])
                ->values()
                ->all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->all(),
        ]);
    }

    public function update(Request $request, TenantMembership $membership, MemberManager $members): RedirectResponse
    {
        $input = $request->validate([
            'role_id' => ['sometimes', 'required', 'string', 'size:26'],
            'status' => ['sometimes', 'required', Rule::enum(MembershipStatus::class)],
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'department_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        /** @var User $actor */
        $actor = $request->user('web');

        // One request, one change: when a later part is refused or not found,
        // the parts before it are not left applied.
        DB::transaction(function () use ($input, $actor, $membership, $members): void {
            if (isset($input['role_id'])) {
                // forCurrentTenant(): a role id from another tenant is simply not found.
                $role = Role::query()->forCurrentTenant()->findOrFail($input['role_id']);

                $members->changeRole($actor, $membership, $role);
            }

            if (isset($input['status'])) {
                $members->setStatus($actor, $membership, MembershipStatus::from($input['status']));
            }

            if (array_key_exists('branch_id', $input) || array_key_exists('department_id', $input)) {
                // Tenant-scoped lookups: an id from another workspace is simply not found.
                $branchId = array_key_exists('branch_id', $input) ? $input['branch_id'] : $membership->branch_id;
                $departmentId = array_key_exists('department_id', $input) ? $input['department_id'] : $membership->department_id;

                $members->place(
                    $actor,
                    $membership,
                    $branchId === null ? null : Branch::query()->findOrFail($branchId),
                    $departmentId === null ? null : Department::query()->findOrFail($departmentId),
                );
            }
        });

        Toast::success(__('Member updated.'));

        return back();
    }

    public function destroy(Request $request, TenantMembership $membership, MemberManager $members): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $members->remove($actor, $membership);

        Toast::success(__('Member removed from the workspace.'));

        return back();
    }
}
