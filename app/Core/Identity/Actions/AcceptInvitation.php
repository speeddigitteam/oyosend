<?php

declare(strict_types=1);

namespace App\Core\Identity\Actions;

use App\Core\Access\RoleManager;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Events\MemberJoined;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Turns a pending invitation into a membership.
 *
 * The invitee either already has an account (the caller has verified it is
 * really them) or gets one created here. Following the emailed link is what
 * proves control of the address, so a new account starts out verified.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly RoleManager $roles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  User|null  $existing  The invitee's account, when they already have one.
     * @param  array{name?: string, password?: string}  $newAccount  Required when `$existing` is null.
     */
    public function handle(Invitation $invitation, ?User $existing, #[SensitiveParameter] array $newAccount = []): User
    {
        return DB::transaction(function () use ($invitation, $existing, $newAccount): User {
            // Re-read under a lock so a link cannot be redeemed twice in parallel.
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer valid.'),
                ]);
            }

            $user = $existing ?? $this->createUser($invitation, $newAccount);

            // Invitations store the address in lower case; an account may not.
            if (Str::lower($user->email) !== Str::lower($invitation->email)) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation was sent to a different email address.'),
                ]);
            }

            $membership = TenantMembership::query()->firstOrNew(['user_id' => $user->getKey()]);

            if ($membership->exists) {
                throw ValidationException::withMessages([
                    'invitation' => __('You are already a member of this workspace.'),
                ]);
            }

            $membership->fill([
                'status' => MembershipStatus::Active,
                'is_owner' => false,
                'joined_at' => Carbon::now(),
            ])->save();

            $role = $invitation->role()->firstOrFail();

            $user->assignRole($role);
            $invitation->update(['accepted_at' => Carbon::now()]);
            $this->roles->flush();

            $this->audit->record('member.joined', $membership, ['role' => $role->name], $user, $user->email);

            MemberJoined::dispatch($membership, $user, $role->name);

            return $user;
        });
    }

    /**
     * @param  array{name?: string, password?: string}  $attributes
     */
    private function createUser(Invitation $invitation, #[SensitiveParameter] array $attributes): User
    {
        $user = new User([
            'name' => $attributes['name'] ?? '',
            'email' => $invitation->email,
            'password' => $attributes['password'] ?? '',
        ]);

        $user->email_verified_at = Carbon::now();
        $user->save();

        return $user;
    }
}
