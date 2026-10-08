<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Team;

use App\Core\Access\Models\Role;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Actions\InviteMember;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    public function destroy(Invitation $invitation, AuditLogger $audit): RedirectResponse
    {
        if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => Carbon::now()]);

            $audit->record('member.invitation_revoked', $invitation, subjectLabel: $invitation->email);
        }

        Toast::success(__('Invitation revoked.'));

        return back();
    }
}
