<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Auth;

use App\Core\Identity\Actions\AcceptInvitation;
use App\Core\Identity\Actions\CompleteWorkspaceLogin;
use App\Core\Identity\Auth\CredentialVerifier;
use App\Core\Identity\Auth\PendingTwoFactorLogin;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class InvitationAcceptanceController
{
    public function show(Request $request, string $token, TenantContext $context): Response
    {
        $invitation = $this->find($token);
        $workspace = $context->require()->name;

        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('Core/Pages/Auth/AcceptInvitation', [
                'workspace' => $workspace,
                'state' => match (true) {
                    $invitation === null => 'invalid',
                    $invitation->accepted_at !== null => 'accepted',
                    $invitation->revoked_at !== null => 'revoked',
                    default => 'expired',
                },
            ]);
        }

        $current = $request->user('web');
        $account = User::query()->where('email', $invitation->email)->first();

        return Inertia::render('Core/Pages/Auth/AcceptInvitation', [
            'workspace' => $workspace,
            'state' => match (true) {
                $current instanceof User && $current->email !== $invitation->email => 'wrong_account',
                $current instanceof User => 'confirm',
                $account !== null => 'existing_account',
                default => 'new_account',
            },
            'token' => $token,
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role->name,
                'inviter' => $invitation->inviter?->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function store(
        Request $request,
        string $token,
        AcceptInvitation $accept,
        CredentialVerifier $verifier,
        CompleteWorkspaceLogin $login,
        PendingTwoFactorLogin $pending,
    ): RedirectResponse {
        $invitation = $this->find($token);

        if ($invitation === null || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        $current = $request->user('web');
        $account = User::query()->where('email', $invitation->email)->first();

        if ($current instanceof User) {
            // Signed in already: it has to be the invited person.
            $user = $accept->handle($invitation, $current);
        } elseif ($account !== null) {
            // An account exists for this address: prove it is theirs.
            $input = $request->validate(['password' => ['required', 'string', 'max:255']]);
            $verified = $verifier->verify('web', $invitation->email, $input['password']);

            if (! $verified instanceof User) {
                throw ValidationException::withMessages(['password' => trans('auth.password')]);
            }

            $user = $accept->handle($invitation, $verified);
        } else {
            $input = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ]);

            $user = $accept->handle($invitation, null, [
                'name' => $input['name'],
                'password' => $input['password'],
            ]);
        }

        if (! $current instanceof User) {
            // An existing account keeps its second factor: joining a workspace
            // is not a way around it.
            if ($user->hasEnabledTwoFactor()) {
                $pending->start($request, 'web', $user, false);

                return redirect()->route('auth.two-factor.challenge');
            }

            $login->handle($request, $user, false, 'invitation');
        }

        Toast::success(__('Welcome aboard. You have joined the workspace.'));

        return redirect()->route('core.dashboard');
    }

    private function find(string $token): ?Invitation
    {
        return Invitation::query()
            ->with(['role', 'inviter'])
            ->where('token_hash', Invitation::hashToken($token))
            ->first();
    }
}
