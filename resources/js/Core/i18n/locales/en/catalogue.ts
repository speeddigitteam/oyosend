/**
 * Labels for things the server names by key: permissions (registered by
 * modules), permission groups, modules and audit events.
 *
 * A module adds its own entries here when it is built. Keys contain dots, so
 * look them up with `permissionLabel()` / `eventLabel()` from
 * `@/Core/lib/labels`, not with `t()` directly.
 */
export default {
    modules: {
        core: 'Workspace',
    },
    permissionGroups: {
        members: 'Members',
        roles: 'Roles',
        audit: 'Audit log',
        settings: 'Workspace settings',
    },
    permissions: {
        'core.members.view': {
            label: 'View members',
            description: 'See who is in the workspace and their roles.',
        },
        'core.members.invite': {
            label: 'Invite members',
            description: 'Send and withdraw invitations.',
        },
        'core.members.manage': {
            label: 'Manage members',
            description: 'Change roles, suspend and remove people.',
        },
        'core.roles.view': {
            label: 'View roles',
            description: 'See roles and what each one can do.',
        },
        'core.roles.manage': {
            label: 'Manage roles',
            description: 'Create roles and change their permissions.',
        },
        'core.audit.view': {
            label: 'View audit log',
            description: 'See who did what, and when.',
        },
        'core.settings.manage': {
            label: 'Manage workspace settings',
            description: 'Change the workspace profile, language, timezone and currency.',
        },
    },
    events: {
        'auth.login': 'Signed in',
        'auth.logout': 'Signed out',
        'auth.login_failed': 'Failed sign-in attempt',
        'auth.password_reset': 'Reset their password',
        'auth.password_changed': 'Changed their password',
        'auth.two_factor_enabled': 'Turned on two-step verification',
        'auth.two_factor_disabled': 'Turned off two-step verification',
        'auth.recovery_codes_regenerated': 'Generated new recovery codes',
        'member.invited': 'Invited a member',
        'member.invitation_revoked': 'Withdrew an invitation',
        'member.joined': 'Joined the workspace',
        'member.role_changed': 'Changed a member’s role',
        'tenant_membership.created': 'Member added',
        'tenant_membership.updated': 'Member updated',
        'tenant_membership.deleted': 'Member removed',
        'role.created': 'Created a role',
        'role.updated': 'Updated a role',
        'role.deleted': 'Deleted a role',
        'role.permissions_changed': 'Changed a role’s permissions',
        'platform.auth.login': 'Signed in to the admin panel',
        'platform.auth.logout': 'Signed out of the admin panel',
        'platform.auth.login_failed': 'Failed admin sign-in attempt',
        'platform.auth.two_factor_enabled': 'Enrolled in two-step verification',
        'platform.admin.created': 'Created an administrator account',
    },
    eventFamilies: {
        auth: 'Sign-in & security',
        member: 'Members',
        tenant_membership: 'Memberships',
        role: 'Roles',
        platform: 'Platform',
    },
};
