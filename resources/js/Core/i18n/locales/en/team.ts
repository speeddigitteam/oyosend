/**
 * Messages for the `team` pages. English is the source of truth for keys.
 *
 * Counts come in two keys, `one` and `other`, picked in code: vue-i18n's own
 * plural syntax needs the pipe character, which the catalogues avoid.
 */
export default {
    members: {
        title: 'Members',
        description: 'Everyone who can sign in to {workspace}, and the role that decides what they can do there.',
        invite: 'Invite member',
        caption: 'Members of this workspace',
        search: 'Search by name or email',
        clearSearch: 'Clear search',
        people: {
            one: '{count} person',
            other: '{count} people',
        },
        columns: {
            member: 'Name',
            role: 'Role',
            status: 'Status',
            twoFactor: 'Two-step',
            joined: 'Joined',
            lastSeen: 'Last seen',
            actions: 'Actions',
        },
        owner: 'Owner',
        noRole: 'No role',
        twoFactorOn: 'Two-step verification is on',
        twoFactorOff: 'Two-step verification is off',
        actions: {
            menu: 'Actions for {name}',
            changeRole: 'Change role',
            suspend: 'Suspend',
            reactivate: 'Reactivate',
            remove: 'Remove from workspace',
        },
        empty: {
            title: 'No members yet',
            body: 'People appear here once they accept an invitation.',
            searchTitle: 'No one matches “{query}”',
            searchBody: 'Check the spelling, or try the email address instead of the name.',
        },
    },
    changeRole: {
        title: 'Change role for {name}',
        description: 'Their access changes as soon as you save.',
        current: 'Current role',
        role: 'New role',
        placeholder: 'Choose a role',
        hint: 'You can hand out any role whose permissions you hold yourself.',
        submit: 'Change role',
    },
    suspend: {
        title: 'Suspend {name}?',
        body: '{name} will not be able to open {workspace} until someone reactivates them. Their role and their history stay as they are.',
        confirm: 'Suspend',
    },
    remove: {
        title: 'Remove {name}?',
        body: '{name} ({email}) loses access to {workspace} straight away, along with their role here. You can invite them again later.',
        confirm: 'Remove member',
    },
    invite: {
        title: 'Invite a member',
        description: 'They get an email with a link to join {workspace}.',
        email: 'Email address',
        role: 'Role',
        rolePlaceholder: 'Choose a role',
        roleHint: 'You can hand out any role whose permissions you hold yourself.',
        submit: 'Send invitation',
        noRoles: 'Every role in this workspace grants something you do not hold yourself, so you cannot hand any of them out. Ask an admin to send this invitation.',
    },
    invitations: {
        title: 'Open invitations',
        description: 'Invited, but not joined yet.',
        invitedAs: 'Invited as {role}',
        invitedAsBy: 'Invited as {role} by {inviter}',
        expires: 'Expires {when}',
        expiredOn: 'Expired {date}',
        withdraw: 'Withdraw',
        withdrawFor: 'Withdraw the invitation for {email}',
        resend: 'Send again',
        resendFor: 'Send again to {email}',
        empty: 'No open invitations',
        emptyBody: 'Invitations you send stay here until they are accepted or withdrawn.',
        confirm: {
            title: 'Withdraw this invitation?',
            body: 'The link sent to {email} stops working. You can invite them again whenever you like.',
            submit: 'Withdraw invitation',
        },
    },
};
