<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * Roles of the people who operate the platform itself.
 */
enum PlatformRole: string
{
    /** Full control of the platform. */
    case SuperAdmin = 'super_admin';

    /** Read access for support work. Acting on a tenant comes with audited impersonation (Phase 2). */
    case SupportAdmin = 'support_admin';

    /**
     * Whether the role holds a `platform.*` ability.
     */
    public function allows(string $ability): bool
    {
        return match ($this) {
            self::SuperAdmin => true,
            self::SupportAdmin => in_array($ability, [
                'platform.dashboard.view',
                'platform.tenants.view',
            ], true),
        };
    }
}
