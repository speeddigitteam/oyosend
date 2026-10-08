/**
 * Messages for the `platform` pages (the Super Admin panel). English is the
 * source of truth for keys.
 *
 * The panel is English-only for now, but every string still lives here.
 */
export default {
    // Signed-out screens. Operators have no "remember me" and no self-service recovery.
    auth: {
        login: {
            documentTitle: 'Admin sign-in',
            title: 'Sign in',
            subtitle: 'to the {app} admin panel',
            email: 'Email',
            password: 'Password',
            submit: 'Sign in',
            restrictedTitle: 'Restricted access',
            restrictedBody: 'For platform staff only. Sign-ins and failed attempts are recorded with their IP address.',
            noReset: 'This panel has no self-service password reset.',
            notWorkspace: 'Workspace members sign in at their own workspace address.',
        },
        challenge: {
            title: 'Two-step verification',
            codeIntro: 'Enter the 6-digit code from your authenticator app.',
            recoveryIntro: 'Enter one of the recovery codes you saved when you set up two-step verification.',
            code: 'Authentication code',
            recoveryCode: 'Recovery code',
            recoveryHint: 'Each recovery code works once.',
            submit: 'Verify and sign in',
            useRecovery: 'Use a recovery code instead',
            useCode: 'Use an authenticator code instead',
            back: 'Back to sign in',
        },
        setup: {
            title: 'Set up two-step verification',
            intro: 'Two-step verification is mandatory for the admin panel. Until it is on, this account cannot open anything else.',
            step: 'Step {number}',
            scan: 'Add this account to your authenticator app',
            confirm: 'Check that it works',
            submit: 'Turn on and continue',
            recoveryNext: 'Next you will be shown recovery codes, once. Have somewhere safe to keep them.',
            signedInAs: 'Signed in as',
        },
    },
    dashboard: {
        description: 'Workspaces, people and the services the platform runs on.',
        stats: {
            tenants: 'Workspaces',
            newThisMonth: '{count} new this month',
            noneThisMonth: 'None new this month',
            active: 'Active',
            activeHint: 'Open to their members',
            pending: 'Pending',
            pendingHint: 'Registered, not yet approved',
            suspended: 'Suspended',
            suspendedHint: 'Access blocked, data kept',
        },
        totals: {
            title: 'Platform totals',
            users: 'People',
            usersHint: 'Accounts that can sign in to a workspace',
            memberships: 'Memberships',
            membershipsHint: 'One person can belong to several workspaces',
            modules: 'Modules',
            modulesHint: 'Compiled into this build',
        },
        recent: {
            title: 'Recent workspaces',
            caption: 'The most recently created workspaces',
            viewAll: 'All workspaces',
            subdomain: 'Subdomain',
            empty: 'No workspaces yet',
            emptyBody: 'The newest workspaces will be listed here as they are created.',
        },
    },
    // Labels for the keys SystemHealth::checks() reports.
    health: {
        title: 'System health',
        refresh: 'Run the checks again',
        allPassing: 'All checks passing',
        // Plural forms are separate keys: vue-i18n's own plural syntax uses a character messages must avoid.
        failing: '{count} of {total} need attention',
        failingOne: '{count} of {total} needs attention',
        // After the name of the queue connection, and on its own for failed jobs.
        pending: '{count} pending',
        failed: '{count} failed',
        checks: {
            database: 'Database',
            cache: 'Cache',
            queue: 'Queue',
            failed_jobs: 'Failed jobs',
        },
    },
    activity: {
        title: 'Panel activity',
        description: 'Sign-ins to this panel and platform-level events.',
        caption: 'Recent activity in the admin panel',
        columns: {
            event: 'Event',
            actor: 'Account',
            ip: 'IP address',
            when: 'When',
        },
        noActor: 'Not signed in',
        empty: 'No panel activity yet',
        emptyBody: 'Sign-ins to this panel and platform-level events will be listed here.',
    },
    tenants: {
        description: 'Each workspace belongs to one customer and is served on its own address. This directory is read-only.',
        caption: 'Workspaces on the platform',
        columns: {
            name: 'Workspace',
            address: 'Address',
            status: 'Status',
            members: 'Members',
            locale: 'Language',
            currency: 'Currency',
            timezone: 'Timezone',
            created: 'Created',
        },
        // Keyed by TenantStatus.
        status: {
            active: 'Active',
            pending: 'Pending',
            suspended: 'Suspended',
        },
        search: 'Search workspaces by name or address',
        searchPlaceholder: 'Name or address',
        statusFilter: 'Filter by status',
        allStatuses: 'All statuses',
        total: '{count} workspaces',
        totalOne: '{count} workspace',
        found: '{count} found',
        opensNewTab: 'opens in a new tab',
        empty: {
            title: 'No workspaces yet',
            body: 'When a workspace is created it will be listed here with its address and status.',
        },
        noMatch: {
            body: 'Try a shorter name or part of the address, or clear the filters.',
            clear: 'Clear filters',
        },
    },
};
