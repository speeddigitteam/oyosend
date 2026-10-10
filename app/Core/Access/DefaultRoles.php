<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\Models\Role;

/**
 * The system roles every tenant starts with, and what each may do in the
 * core module. Tenants can add their own roles and adjust these - except
 * Owner, which always has everything.
 */
final class DefaultRoles
{
    /**
     * `scopes` narrows a grant; a permission not named there reaches every record.
     *
     * @return array<string, array{description: string, permissions: list<string>, scopes?: array<string, string>}>
     */
    public static function definitions(): array
    {
        return [
            Role::OWNER => [
                'description' => 'Full control of the workspace, including billing and ownership.',
                // Granted implicitly through the membership's owner flag.
                'permissions' => [],
            ],
            Role::ADMIN => [
                'description' => 'Manages people, roles, contacts and workspace settings.',
                'permissions' => [
                    'core.members.view',
                    'core.members.invite',
                    'core.members.manage',
                    'core.roles.view',
                    'core.roles.manage',
                    'core.audit.view',
                    'core.settings.manage',
                    'core.organization.view',
                    'core.organization.manage',
                    'core.contacts.view',
                    'core.contacts.manage',
                    'core.contacts.delete',
                ],
            ],
            Role::MANAGER => [
                'description' => 'Leads a team: can see who is in the workspace, invite people and work with every contact.',
                'permissions' => [
                    'core.members.view',
                    'core.members.invite',
                    'core.roles.view',
                    'core.organization.view',
                    'core.contacts.view',
                    'core.contacts.manage',
                ],
            ],
            Role::EMPLOYEE => [
                'description' => 'Works in the apps they have been given access to.',
                'permissions' => [
                    'core.members.view',
                    'core.organization.view',
                    'core.contacts.view',
                    'core.contacts.manage',
                ],
                // Sees every contact, changes only their own.
                'scopes' => [
                    'core.contacts.manage' => 'own',
                ],
            ],
        ];
    }
}
