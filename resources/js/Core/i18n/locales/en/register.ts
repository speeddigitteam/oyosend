/**
 * Messages for signing up on the central site: the form, the "check your
 * email" page, and the page the emailed link opens.
 *
 * The form must never suggest whether an email address already has an
 * account, and nothing here may say that something was created before the
 * link has been confirmed.
 */
export default {
    form: {
        title: 'Create a workspace',
        subtitle: 'One address for your company and its team to sign in at.',
        about: 'About you',
        workspace: 'Your workspace',
        name: 'Your name',
        email: 'Work email',
        emailHint: 'We email a link to this address to confirm that it is yours.',
        password: 'Choose a password',
        passwordConfirmation: 'Confirm password',
        company: 'Company name',
        address: 'Workspace address',
        // An example of the value itself.
        addressPlaceholder: 'your-company',
        addressHint: 'Where your team will sign in. Lower-case letters, digits and hyphens; at least three characters.',
        locale: 'Language',
        localeHint: 'Used for the workspace and for the confirmation email. It can be changed later.',
        plan: 'Plan',
        interval: 'Billing interval',
        // Under the plans, for the one selected.
        trialNote: 'No payment now. The trial starts when the workspace is created.',
        invoiceNote: 'No payment now. The first invoice is issued when the workspace is created.',
        approval: 'New workspaces are reviewed before they can be used. After you confirm your email, we will write to you when yours is approved.',
        submit: 'Sign up',
        next: 'We will email you a link to confirm your address. Nothing is created before you use it.',
        existing: 'Already have a workspace?',
        find: 'Find it and sign in',
    },
    sent: {
        title: 'Check your email',
        body: 'We sent a link to confirm your address.',
        sentTo: 'Sent to',
        valid: {
            one: 'The link works once and is valid for {count} hour.',
            other: 'The link works once and is valid for {count} hours.',
        },
        nothingYet: 'Nothing has been created yet. The workspace is created when you open the link and confirm.',
        missing: {
            title: 'No email after a few minutes?',
            body: 'Check your spam folder and that the address above is right, then sign up again. The new link replaces this one.',
        },
        again: 'Sign up again',
        home: 'Back to the start page',
    },
    // The page the emailed link opens, by its `state`.
    confirm: {
        title: 'Create {company}',
        body: 'Your email address is confirmed. Nothing has been created yet: check the details, then create the workspace.',
        company: 'Company',
        address: 'Workspace address',
        owner: 'Owner',
        plan: 'Plan',
        submit: 'Create workspace',
        once: 'This link works once.',
    },
    ready: {
        title: '{company} is ready',
        body: 'The workspace has been created. Sign in at its own address.',
        // The sign-up form's password is only used for an address that had no account.
        password: 'If this email already had an Oyosend account, its password has not changed: sign in with that one, not the one from the sign-up form.',
        signIn: 'Go to sign in',
    },
    pending: {
        title: '{company} is waiting for approval',
        body: 'The workspace has been created and will be reviewed before it can be used. We will email you when it is approved.',
    },
    invalid: {
        title: 'This link can no longer be used',
        body: 'It may have expired, been replaced by a newer sign-up, or been used already.',
        again: 'Sign up again',
        home: 'Back to the start page',
    },
};
