/**
 * Messages for the platform settings page of the Super Admin panel. English
 * is the source of truth for keys.
 */
export default {
    description: 'What can be changed while the platform is running. Everything else is configuration and needs a deploy.',
    registration: {
        title: 'Sign-up',
        description: 'What happens when someone signs up on the public site.',
        mode: 'Registration',
        // Keyed by RegistrationMode.
        modes: {
            open: {
                label: 'Open',
                description: 'Anyone can sign up. The workspace can be used as soon as the email address is confirmed.',
            },
            approval: {
                label: 'Approval',
                description: 'Anyone can sign up, but the workspace stays closed until an operator approves it.',
            },
            closed: {
                label: 'Closed',
                description: 'There is no sign-up page. Workspaces are created from this panel only.',
            },
        },
    },
    billing: {
        title: 'Billing',
        description: 'Timings for every workspace on a paid plan, and what an unpaid invoice says about paying.',
        graceDays: 'Grace period',
        graceDaysHint: 'Days a workspace keeps working after a payment was due. From 0 to 90.',
        invoiceLeadDays: 'Invoice lead time',
        invoiceLeadDaysHint: 'Days before a paid period or a trial ends that the next invoice is issued. From 0 to 60.',
        offlineInstructions: 'How to pay outside a gateway',
        offlineInstructionsHint:
            'Shown on every unpaid invoice: bank details, a mobile banking number, who to call. Leave it empty and nothing is shown.',
    },
    gateways: {
        title: 'Payment gateways',
        description:
            'Read-only. A gateway’s credentials live in the server’s environment; they are never stored in the database or shown on a page. To turn a gateway on, set its credentials there and deploy.',
        configured: 'Configured',
        notConfigured: 'Not configured',
        // Under a gateway's name.
        configuredHint: 'Has its credentials',
        notConfiguredHint: 'No credentials, so it is not offered to workspaces',
        none: 'No payment gateway in this build',
        noneBody: 'Workspaces pay outside a gateway, and an operator records each payment on its invoice.',
    },
    unsaved: 'You have unsaved changes.',
};
