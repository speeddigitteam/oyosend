/**
 * Messages for the workspace's own pages - settings, first-run setup, the
 * "workspace locked" screen - and for the notices the workspace layout shows
 * on every page (support session, subscription).
 */
export default {
    // Groups of fields, shared by the settings page and first-run setup.
    sections: {
        company: {
            title: 'Company',
            description: 'Who this workspace belongs to.',
        },
        contact: {
            title: 'Contact details',
            description: 'How the company can be reached.',
        },
        address: {
            title: 'Address',
            description: 'The registered or main office of the company.',
        },
        regional: {
            title: 'Regional settings',
            description: 'How dates, times and money are shown to everyone in the workspace.',
        },
    },
    fields: {
        name: 'Workspace name',
        nameHint: 'The name your team sees in the sidebar and in emails.',
        legalName: 'Legal name',
        legalNameHint: 'The registered name of the company, if it is different.',
        taxId: 'Tax ID',
        taxIdHint: 'For example a BIN, TIN or VAT number.',
        email: 'Email',
        phone: 'Phone',
        website: 'Website',
        websiteHint: 'The full address, starting with http:// or https://',
        street: 'Street address',
        city: 'City',
        postalCode: 'Postal code',
        country: 'Country',
        noCountry: 'Not set',
        language: 'Language',
        languageHint: 'For anyone who has not chosen a language of their own.',
        timezone: 'Time zone',
        currency: 'Currency',
        preview: {
            title: 'Preview',
            time: 'The time there now',
            money: 'An amount',
        },
    },
    settings: {
        title: 'Workspace settings',
        description: 'The company profile, regional settings and logo of {workspace}.',
        unsaved: 'You have unsaved changes.',
        logo: {
            title: 'Logo',
            description: 'Shown in the sidebar to everyone in {workspace}.',
            alt: 'Logo of {workspace}',
            empty: 'No logo yet. The sidebar shows the initials of the workspace.',
            requirements: 'A PNG, JPG or WebP file, up to 1 MB and 2000 pixels on each side.',
            upload: 'Upload logo',
            replace: 'Replace',
            tooLarge: 'That file is larger than 1 MB. Choose a smaller one.',
            wrongType: 'Choose a PNG, JPG or WebP image.',
            removeDialog: {
                title: 'Remove the logo?',
                body: 'The sidebar goes back to showing the initials of {workspace}, and the file is deleted.',
                confirm: 'Remove logo',
            },
        },
        url: {
            title: 'Workspace address',
            description: 'Where your team signs in.',
            hint: 'The address cannot be changed: links, bookmarks and sign-in all depend on it.',
            copy: 'Copy address',
            copied: 'Address copied',
        },
    },
    onboarding: {
        eyebrow: 'First-time setup',
        title: 'Set up {workspace}',
        description: 'Check a few details before you start. Everything here can be changed later in the workspace settings.',
        deviceTimezone: 'Your device is set to {timezone}.',
        useDeviceTimezone: 'Use it',
        later: 'The address, tax ID and logo can be added later in the workspace settings.',
        submit: 'Finish setup',
    },
    // The screen a member gets when the subscription has ended.
    locked: {
        title: '{workspace} is locked',
        expired: 'Its subscription ended because a payment was not received, so the workspace is closed for now.',
        canceled: 'Its subscription was canceled, so the workspace is closed for now.',
        safe: 'Nothing has been deleted. The workspace owner can restore access from billing.',
        signedInAs: 'Signed in as {email}',
        account: 'Account settings',
    },
    // The subscription notice at the top of every page, shown only when something needs attention.
    notice: {
        label: 'Subscription status',
        billing: 'Go to billing',
        trialEnding: 'The trial of the {plan} plan ends on {date}.',
        trialEnded: 'The trial of the {plan} plan has ended.',
        pastDue: 'A payment for the {plan} plan is overdue. If it is not paid, the workspace will be locked from {date}.',
        pastDueUndated: 'A payment for the {plan} plan is overdue.',
        ending: 'The {plan} plan is set to end on {date}. The workspace will be locked after that.',
        locked: 'This workspace is locked because its {plan} plan has ended.',
        lockedOwner: 'Choose a plan in billing to open it again.',
        lockedMember: 'The workspace owner can restore it from billing.',
        tellOwner: 'Let the workspace owner know.',
    },
    // The banner shown on every page while a Super Admin is signed in as the owner.
    support: {
        title: 'Support session',
        by: 'Opened by {by}',
        ends: 'Ends at {time}',
        audited: 'You are signed in as the workspace owner. Everything done here is recorded in the audit log.',
        end: 'End session',
    },
};
