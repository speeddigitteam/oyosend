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

    /** Read access for support work. Changing anything, and entering a workspace, is left to a Super Admin. */
    case SupportAdmin = 'support_admin';

    /**
     * Every `platform.*` ability. A Super Admin holds all of them.
     *
     * @var list<string>
     */
    public const array ABILITIES = [
        'platform.dashboard.view',
        'platform.tenants.view',
        'platform.tenants.manage',
        'platform.tenants.impersonate',
        'platform.plans.view',
        'platform.plans.manage',
        'platform.billing.view',
        'platform.billing.manage',
        'platform.audit.view',
        'platform.settings.manage',
        'platform.website.view',
        'platform.website.manage',
    ];

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
                'platform.plans.view',
                'platform.billing.view',
                'platform.audit.view',
                // Reading the website's messages is support work; answering them happens by email.
                'platform.website.view',
            ], true),
        };
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_values(array_filter(self::ABILITIES, $this->allows(...)));
    }
}
