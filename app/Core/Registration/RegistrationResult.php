<?php

declare(strict_types=1);

namespace App\Core\Registration;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;

/**
 * What confirming a sign-up produced.
 */
final readonly class RegistrationResult
{
    /**
     * @param  bool  $accountCreated  False when the address already had an account, which now owns one more workspace.
     */
    public function __construct(
        public Tenant $tenant,
        public User $user,
        public bool $accountCreated,
    ) {}

    /**
     * Waiting for a Super Admin's approval before it can be used.
     */
    public function awaitsApproval(): bool
    {
        return ! $this->tenant->isAccessible();
    }
}
