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
    issuer: {
        title: 'Invoice issuer',
        description: 'The prefix on platform invoice numbers and the business details printed on every invoice.',
        invoicePrefix: 'Invoice number prefix',
        invoicePrefixHint: 'Leads the number, e.g. “INV” in INV-2026-000042. Letters, numbers, . _ / -.',
        name: 'Business name',
        address: 'Address',
        email: 'Billing email',
        taxId: 'Tax / VAT number',
    },
    gateways: {
        title: 'Payment gateways',
        description:
            'Enter a gateway’s credentials here and they are stored encrypted; they are never shown again or logged. A gateway with no credentials here falls back to the server environment, and one with neither is not offered.',
        configured: 'Configured',
        notConfigured: 'Not configured',
        // Where the gateway's credentials come from.
        source: {
            panel: 'Credentials set in this panel',
            environment: 'Using the server environment',
            none: 'No credentials, so it is not offered to workspaces',
        },
        // Credential field labels.
        fields: {
            store_id: 'Store ID',
            store_password: 'Store password',
            signature_key: 'Signature key',
        },
        secretStored: 'Stored — leave blank to keep',
        sandbox: 'Sandbox mode (test credentials)',
        enabled: 'Offer this gateway to workspaces',
        save: 'Save credentials',
        clear: 'Clear',
        none: 'No payment gateway in this build',
        noneBody: 'Workspaces pay outside a gateway, and an operator records each payment on its invoice.',
    },
    unsaved: 'You have unsaved changes.',
};
