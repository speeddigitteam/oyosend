/**
 * Messages for the invoice pages of the Super Admin panel
 * (`Platform/Pages/Billing`). English is the source of truth for keys.
 *
 * The words for statuses, kinds, methods and the invoice itself are shared
 * with the workspace's billing pages: `billing.terms`, `billing.document`,
 * `billing.payments`.
 *
 * Counts come in two keys, `one` and `other`, picked in code.
 */
export default {
    // Every invoice the platform has issued.
    invoices: {
        title: 'Invoices',
        description: 'Every invoice the platform has issued, across all workspaces.',
        caption: 'Invoices across all workspaces',
        search: 'Search invoices',
        searchPlaceholder: 'Invoice number or workspace',
        statusFilter: 'Filter by status',
        allStatuses: 'All statuses',
        found: '{count} found',
        total: {
            one: '{count} invoice',
            other: '{count} invoices',
        },
        columns: {
            number: 'Invoice',
            workspace: 'Workspace',
            kind: 'For',
            issued: 'Issued',
            due: 'Due',
            total: 'Total',
            status: 'Status',
        },
        open: 'Open invoice {number}',
        workspaceGone: 'Deleted workspace',
        totals: {
            open: 'Unpaid',
            openCount: {
                one: '{count} invoice waiting for payment',
                other: '{count} invoices waiting for payment',
            },
            openNone: 'Nothing is waiting for payment.',
            paid: 'Paid this month',
            paidCount: {
                one: '{count} invoice paid',
                other: '{count} invoices paid',
            },
            paidNone: 'Nothing has been paid this month yet.',
        },
        empty: {
            title: 'No invoices yet',
            body: 'An invoice appears here when a workspace takes or renews a paid plan.',
        },
        noMatch: {
            body: 'Try another invoice number or workspace name, or a different status.',
            clear: 'Clear filters',
        },
    },

    // One invoice, and what an operator can do to it.
    invoice: {
        back: 'All invoices',
        workspace: {
            title: 'Workspace',
            open: 'Open workspace',
            gone: 'The workspace this invoice was issued to no longer exists.',
        },
        payments: {
            description: 'Every attempt to pay this invoice, online or recorded here.',
            bankReference: 'Reference given: {reference}',
            note: 'Note: {note}',
        },
        unpaid: {
            title: 'Waiting for payment',
            body: 'The workspace can pay this invoice online. Money that arrives another way is recorded here.',
            expired: 'This offer to change plan has run out, so a payment can no longer be recorded for it. It can only be withdrawn.',
            viewOnly: 'Your role can see invoices but not record payments or withdraw them.',
        },
        record: {
            action: 'Record payment',
            title: 'Record a payment',
            description: 'For money that arrived outside a payment gateway. Recording it marks invoice {number} as paid and starts what it pays for.',
            amount: 'Amount received',
            amountHint: 'An invoice is always paid in full by one payment.',
            method: 'How it was paid',
            methodPlaceholder: 'Choose a method',
            reference: 'Reference',
            referenceHint: 'A bank transaction ID, cheque number or receipt number.',
            note: 'Note',
            noteHint: 'Kept with the payment. The workspace does not see it.',
            paidAt: 'Date received',
            paidAtHint: 'Taken as a day on the clock of the workspace.',
            submit: 'Record payment',
        },
        withdraw: {
            action: 'Withdraw invoice',
            title: 'Withdraw invoice {number}?',
            body: 'The workspace is no longer asked to pay it. This cannot be undone. If something is still owed, the next billing run issues a new invoice.',
            reason: 'Reason',
            reasonHint: 'At least five characters. Recorded in the audit log of the workspace.',
            confirm: 'Withdraw invoice',
        },
    },
};
