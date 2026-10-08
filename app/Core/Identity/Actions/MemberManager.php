<?php

declare(strict_types=1);

namespace App\Core\Identity\Actions;

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Events\MemberRemoved;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to an existing member of the active workspace.
 *
 * The owner can never be changed from here, and nobody can change, suspend or
 * remove themselves - that prevents both lock-outs and self-promotion.
 */
final class MemberManager
{
    public function __construct(
        private readonly RoleManager $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function changeRole(User $actor, TenantMembership $membership, Role $role): void
    {
        $this->guardMutable($actor, $membership);

        if (! $this->roles->canAssign($actor, $role)) {
            throw ValidationException::withMessages([
                'role_id' => __('You cannot assign this role.'),
            ]);
        }

        DB::transaction(function () use ($membership, $role): void {
            $user = $membership->user()->firstOrFail();
            $previous = $user->roles()->pluck('roles.name')->all();

            $user->syncRoles([$role]);
            $this->roles->flush();

            $this->audit->record('member.role_changed', $membership, [
                'old' => $previous,
                'attributes' => [$role->name],
            ]);
        });
    }

    public function setStatus(User $actor, TenantMembership $membership, MembershipStatus $status): void
    {
        $this->guardMutable($actor, $membership);

        DB::transaction(function () use ($membership, $status): void {
            // The Auditable trait records the status change.
            $membership->update(['status' => $status]);
            $this->roles->flush();
        });
    }

    public function remove(User $actor, TenantMembership $membership): void
    {
        $this->guardMutable($actor, $membership);

        DB::transaction(function () use ($membership): void {
            $user = $membership->user()->firstOrFail();

            // Detaches this workspace's roles only: the relation is team-scoped.
            $user->syncRoles([]);
            $membership->delete();
            $this->roles->flush();

            MemberRemoved::dispatch($membership->id, $user->id, $user->email);
        });
    }

    private function guardMutable(User $actor, TenantMembership $membership): void
    {
        if ($membership->is_owner) {
            throw ValidationException::withMessages([
                'member' => __('The workspace owner cannot be changed here.'),
            ]);
        }

        if ($membership->user_id === $actor->getKey()) {
            throw ValidationException::withMessages([
                'member' => __('You cannot change your own membership.'),
            ]);
        }
    }
}
