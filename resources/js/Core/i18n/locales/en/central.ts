/**
 * Messages for the `central` pages. English is the source of truth for keys.
 *
 * This is the public page at the root domain. Only describe what is built:
 * no apps, pricing or sign-up until they exist.
 */
export default {
    welcome: {
        title: 'Find your workspace',
        headline: 'Your work has an address.',
        lede: 'Oyosend gives a company and its team a workspace of their own: one address to sign in at, and data no other company can see.',
        find: {
            title: 'Find your workspace',
            label: 'Workspace address',
            placeholder: 'your-company',
            hint: 'The part before .{domain}. You can also paste the full address.',
            submit: 'Go to sign in',
        },
        today: {
            label: 'What is here today',
            isolation: {
                title: 'Separate workspaces',
                body: 'Each company’s members, roles and records live in its own workspace, apart from every other.',
            },
            languages: {
                title: 'English and বাংলা',
                body: 'Each person chooses their language, and whether numbers appear in Latin or Bengali digits.',
            },
            roles: {
                title: 'Roles and permissions',
                body: 'Access follows a person’s role. Nobody can hand out a permission they do not hold themselves.',
            },
            security: {
                title: 'Two-step verification and an audit trail',
                body: 'Sign-in can require a code from an authenticator app. Sign-ins, invitations and role changes are recorded.',
            },
        },
    },
};
