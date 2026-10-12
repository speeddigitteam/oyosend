<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Team;

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Actions\InviteMember;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class InvitationController
{
    public function store(Request $request, InviteMember $invite): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role_id' => ['required', 'string', 'size:26'],
        ]);

        /** @var User $inviter */
        $inviter = $request->user('web');

        $role = Role::query()->forCurrentTenant()->findOrFail($input['role_id']);

        $invitation = $invite->handle($inviter, $input['email'], $role);

        Toast::success(__('Invitation sent to :email.', ['email' => $invitation->email]));

        return back();
    }

    public function destroy(Request $request, Invitation $invitation, AuditLogger $audit, RoleManager $roles): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        // The role hierarchy applies to withdrawing as it does to inviting.
        if (! $roles->canAssign($actor, $invitation->role)) {
            throw ValidationException::withMessages([
                'invitation' => __('You cannot withdraw an invitation for a role you do not hold.'),
            ]);
        }

        if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => Carbon::now()]);

            $audit->record('member.invitation_revoked', $invitation, subjectLabel: $invitation->email);
        }

        Toast::success(__('Invitation withdrawn.'));

        return back();
    }
}
