<?php

declare(strict_types=1);

namespace App\Core\Identity\Actions;

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Events\MemberInvited;
use App\Core\Identity\Mail\InvitationMail;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InviteMember
{
    public function __construct(
        private readonly RoleManager $roles,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Invite an address to the active workspace. Inviting an address that
     * already has an open invitation replaces it (the old link stops working).
     *
     * @throws ValidationException
     */
    public function handle(User $inviter, string $email, Role $role): Invitation
    {
        $email = Str::lower(trim($email));

        if (! $this->roles->canAssign($inviter, $role)) {
            throw ValidationException::withMessages([
                'role_id' => __('You cannot invite someone with this role.'),
            ]);
        }

        $alreadyMember = TenantMembership::query()
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => __('This person is already a member of the workspace.'),
            ]);
        }

        // The token is shown to the invitee once, by email, and only its hash is kept.
        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($inviter, $email, $role, $token): Invitation {
            Invitation::query()
                ->open()
                ->where('email', $email)
                ->lockForUpdate()
                ->get()
                ->each(function (Invitation $open): void {
                    $open->update(['revoked_at' => Carbon::now()]);
                });

            $invitation = Invitation::query()->create([
                'email' => $email,
                'role_id' => $role->getKey(),
                'invited_by' => $inviter->getKey(),
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => Carbon::now()->addDays((int) config('platform.invitations.expires_after_days', 7)),
            ]);

            $this->audit->record('member.invited', $invitation, ['role' => $role->name], subjectLabel: $email);

            return $invitation;
        });

        // In the language the invitee chose if they already have an account, otherwise the workspace's.
        $locale = User::query()->where('email', $email)->value('locale') ?? $this->context->require()->locale;

        Mail::to($email)->locale($locale)->queue(new InvitationMail($invitation, $token));

        MemberInvited::dispatch($invitation);

        return $invitation;
    }
}
