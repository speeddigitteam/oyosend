<?php

declare(strict_types=1);

namespace App\Core\Identity\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Auth\CredentialVerifier;
use App\Core\Identity\Auth\LoginThrottle;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * First step of a workspace sign-in: the password is right and the person
 * is an active member of this workspace.
 *
 * A wrong password, an unknown address and a real account that is simply not
 * a member here all fail identically, so the form cannot be used to learn
 * who has an account or which workspaces they belong to.
 */
final class VerifyWorkspaceCredentials
{
    public function __construct(
        private readonly CredentialVerifier $verifier,
        private readonly LoginThrottle $throttle,
        private readonly AccessChecker $access,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $email, #[SensitiveParameter] string $password, ?string $ip): User
    {
        $key = LoginThrottle::key('tenant:'.$this->context->requireId(), $email, $ip);

        $this->throttle->ensureNotLocked($key);

        $user = $this->verifier->verify('web', $email, $password);

        if (! $user instanceof User || $this->access->membership($user) === null) {
            $this->throttle->hit($key);
            $this->audit->record('auth.login_failed', properties: ['email' => $email]);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $this->throttle->clear($key);

        return $user;
    }
}
