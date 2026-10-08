<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Widgets;

use App\Core\Identity\Models\User;

/**
 * The signed-in person's own account security. Needs no permission: it only
 * ever describes the person looking at it.
 */
final class SecurityWidget
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        return [
            'twoFactorEnabled' => $user->hasEnabledTwoFactor(),
            'recoveryCodesRemaining' => $user->hasEnabledTwoFactor() ? count($user->recoveryCodes()) : null,
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'emailVerified' => $user->email_verified_at !== null,
        ];
    }
}
