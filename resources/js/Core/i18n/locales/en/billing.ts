/**
 * Messages for platform billing: what the workspace pays the platform.
 * English is the source of truth for keys.
 *
 * `terms` is the shared vocabulary. The other sections belong to the
 * workspace's billing pages (`Core/Pages/Billing`) and to the pieces they
 * share with the Super Admin panel (`Core/Components/Billing`).
 *
 * Counts come in two keys, `one` and `other`, picked in code: vue-i18n's own
 * plural syntax needs the pipe character, which the catalogues avoid.
 */
export default {
    /*
     * The vocabulary of billing, shared by the workspace's billing pages and
     * the Super Admin panel. Keys under `subscriptionStatus`, `invoiceStatus`,
     * `invoiceKind`, `paymentStatus`, `interval` and `method` are the values
     * the server sends.
     */
    terms: {
        interval: {
            monthly: 'Monthly',
            yearly: 'Yearly',
        },
        perInterval: {
            monthly: 'per month',
            yearly: 'per year',
        },
        subscriptionStatus: {
            trialing: 'Trial',
            active: 'Active',
            past_due: 'Payment overdue',
            expired: 'Expired',
            canceled: 'Canceled',
            none: 'No plan',
        },
        invoiceStatus: {
            open: 'Unpaid',
            paid: 'Paid',
            void: 'Withdrawn',
            overdue: 'Overdue',
        },
        invoiceKind: {
            subscription: 'New plan',
            renewal: 'Renewal',
            change: 'Change of plan',
            app_purchase: 'App purchase',
            app_renewal: 'App renewal',
        },
        appBillingType: {
            monthly: 'monthly',
            yearly: 'yearly',
            one_time: 'one-time',
            usage_based: 'usage-based',
            enterprise: 'enterprise',
            free: 'free',
        },
        paymentStatus: {
            pending: 'Pending',
            succeeded: 'Paid',
            failed: 'Failed',
            canceled: 'Canceled',
        },
        // Online gateways show their own name; this is money recorded by an operator.
        offlineGateway: 'Recorded by support',
        method: {
            bank_transfer: 'Bank transfer',
            cash: 'Cash',
            cheque: 'Cheque',
            mobile_banking: 'Mobile banking',
            other: 'Other',
        },
        // Invoice lines, by their `type`.
        line: {
            plan: '{plan} plan, {interval}',
            credit: 'Credit for unused time on the previous plan',
            app: '{app} — {offer}, {billingType}',
        },
        free: 'Free',
        unlimited: 'Unlimited',
        notOffered: 'Not offered',
        trialDays: '{count}-day free trial',
        // A plan's limited-time offer, and what paying yearly saves.
        offer: {
            until: 'Offer until {date}',
            was: 'Usual price',
            off: '{percent} off',
            yearlySaving: 'Save {percent} yearly',
            keeps: 'Take it before the offer ends and you keep this price when you renew.',
        },
    },

    // The billing page of a workspace.
    index: {
        title: 'Billing',
        description: 'The plan {workspace} is on, how much of it is in use, and the invoices for it.',
    },

    // The plan the workspace is on, and where it stands with paying for it.
    current: {
        title: 'Current plan',
        none: {
            manage: 'This workspace is not on a plan. Choose one below.',
            view: 'This workspace is not on a plan. Someone whose role manages billing can choose one.',
        },
        state: {
            trial: 'On trial until {date}. The first invoice is issued shortly before the trial ends.',
            renews: 'Renews on {date}.',
            free: 'Nothing to pay and nothing to renew.',
            pastDue: 'A payment is overdue. Unless the unpaid invoice is paid by {date}, the workspace is locked.',
            ending: 'Ends on {date}. After that the workspace is locked until a plan is taken again.',
            expired: 'Ended on {date} because an invoice stayed unpaid. The workspace is locked until a plan is active again.',
            canceled: 'Ended on {date}. The workspace is locked until a plan is taken again.',
        },
        // {interval} is one of `terms.interval`.
        pending: 'Changes to the {plan} plan ({interval}) on {date}.',
        pendingUndo: 'To stay on the current plan instead, choose it again below.',
        period: 'Paid period',
        cancel: 'Cancel subscription',
        resume: 'Keep the subscription',
        viewOnly: 'You can see billing but not change it.',
    },

    // Stopping the subscription. The dialog's other button already says "Cancel".
    cancel: {
        title: 'End the subscription?',
        paid: 'The workspace keeps working until {date}, the end of the period you have paid for. After that it is locked until a plan is taken again. Its data is kept, and you can change your mind before then.',
        trial: 'The trial runs until {date} and no invoice is issued. After that the workspace is locked until a plan is taken again. Its data is kept, and you can change your mind before then.',
        pastDue: 'A payment is overdue, so there is no paid time left to run. The subscription ends now and the workspace is locked until a plan is taken again. Its data is kept.',
        withdrawsInvoice: 'Invoice {number}, which is still unpaid, is withdrawn.',
        confirm: 'End subscription',
    },

    // An invoice that is waiting to be paid.
    due: {
        title: 'Invoice {number} is waiting for payment',
        titleOverdue: 'Invoice {number} is overdue',
        amount: '{amount} to pay.',
        payBy: '{amount} to pay by {date}.',
        wasDue: '{amount} was due on {date}.',
        unlocks: 'The workspace opens again as soon as it is paid.',
        changes: 'Your plan changes as soon as it is paid.',
        offerExpired: 'This offer to change plan ran out on {date} and can no longer be paid. Choose the plan again to get a current invoice.',
        pay: 'Pay invoice',
        view: 'View invoice',
    },

    // What became of a payment, shown when the payer comes back from a gateway.
    returned: {
        succeeded: {
            title: 'Payment received',
            body: 'We received {amount}.',
        },
        failed: {
            title: 'The payment did not go through',
            body: 'Nothing was paid.',
        },
        canceled: {
            title: 'The payment was canceled',
            body: 'It was stopped before it finished, so nothing was paid.',
        },
        pending: {
            title: 'Waiting for the payment to be confirmed',
            body: 'We are waiting for the payment provider to confirm this payment. There is nothing more you need to do; check again in a moment.',
            refresh: 'Check again',
        },
        retry: 'You can try again from the invoice.',
        reason: 'The payment provider said: {reason}',
        reference: 'Payment reference {reference}',
    },

    usage: {
        title: 'Usage',
        description: 'What the workspace uses of what its plan allows.',
        of: '{used} of {limit}',
        noLimit: '{used}, no limit',
        metered: 'Counted since the start of the billing period.',
        reached: 'The limit is reached. A plan with a higher limit is needed to add more.',
        empty: 'Nothing is counted for this workspace yet.',
    },

    // The plans a workspace can choose for itself.
    plans: {
        title: 'Plans',
        description: 'Before a plan changes you are told what will happen and what there is to pay.',
        viewOnly: 'Changing the plan is for people whose role manages billing.',
        interval: 'Show prices for',
        current: 'Current plan',
        retired: 'No longer sold',
        notSold: {
            monthly: 'Not sold monthly',
            yearly: 'Not sold yearly',
        },
        // What a plan that is not sold for the chosen interval is sold for.
        soldOnly: {
            monthly: 'This plan is billed monthly only.',
            yearly: 'This plan is billed yearly only.',
        },
        trial: 'A {count}-day free trial for a workspace that signs up on this plan.',
        limits: 'Limits',
        includes: 'Includes',
        choose: 'Choose plan',
        chooseNamed: 'Choose the {plan} plan',
        keep: 'Stay on this plan',
        switchBilling: {
            monthly: 'Switch to monthly billing',
            yearly: 'Switch to yearly billing',
        },
        onThisPlan: 'The workspace is on this plan.',
        empty: {
            title: 'No plans to choose from',
            body: 'No plan is on offer at the moment.',
        },
        confirm: {
            title: 'Change to the {plan} plan?',
            titleKeep: 'Stay on the {plan} plan?',
            price: 'Price',
            submit: 'Change plan',
            submitKeep: 'Stay on this plan',
            // One per outcome SubscriptionManager::changePlan() can have.
            outcome: {
                freeNow: 'The workspace moves to {plan} straight away. There is nothing to pay.',
                freeLater: 'The workspace stays as it is until {date}, the end of the period you have paid for, and moves to {plan} after that. Nothing more is charged.',
                trial: 'The workspace moves to {plan} straight away. The trial still ends on {date}, and the first invoice will be for this plan.',
                keepEnding: 'The subscription is no longer set to end. It carries on as before.',
                keepPending: 'The change of plan that was scheduled is dropped. The workspace stays on {plan}.',
                later: 'The workspace stays as it is until {date}, the end of the period you have paid for. {plan} starts then, and the next invoice will be for it.',
                upgrade: 'You get an invoice for {amount}, less a credit for the unused time on the current plan. {plan} starts as soon as that invoice is paid, and at once if the credit covers all of it. Nothing changes before then.',
                invoice: 'You get an invoice for {amount}. {plan} starts as soon as it is paid. Nothing changes before then.',
                first: 'The workspace moves to {plan} now and you get an invoice for {amount}. If that invoice stays unpaid past the grace period, the workspace is locked until it is paid.',
                replace: 'You get an invoice for {amount}, and {plan} starts as soon as it is paid. The day the workspace is locked if nothing is paid stays the same.',
                reopen: 'You get an invoice for {amount}. The workspace opens again, on {plan}, as soon as it is paid.',
            },
            replacesInvoice: 'Invoice {number}, which is still unpaid, is withdrawn.',
        },
    },

    // The list of invoices on the billing page.
    invoices: {
        title: 'Invoices',
        description: 'The most recent invoices issued to this workspace.',
        caption: 'Invoices issued to this workspace',
        columns: {
            number: 'Invoice',
            issued: 'Issued',
            kind: 'For',
            period: 'Period',
            total: 'Total',
            status: 'Status',
        },
        open: 'Open invoice {number}',
        empty: {
            title: 'No invoices yet',
            body: 'An invoice appears here when a paid plan is taken or renewed.',
        },
    },

    // The invoice as a document. Also printed in the Super Admin panel.
    document: {
        heading: 'Invoice',
        from: 'From',
        billedTo: 'Billed to',
        taxId: 'Tax ID {id}',
        number: 'Invoice number',
        kind: 'For',
        issued: 'Issued',
        due: 'Due',
        paid: 'Paid',
        withdrawn: 'Withdrawn',
        period: 'Period',
        periodRange: '{from} to {to}',
        lines: 'What this invoice is for',
        description: 'Description',
        amount: 'Amount',
        subtotal: 'Subtotal',
        credit: 'Credit',
        total: 'Total',
        totalDue: 'Amount due',
        totalPaid: 'Amount paid',
    },

    // The invoice page of a workspace.
    invoice: {
        title: 'Invoice {number}',
        back: 'Back to billing',
        print: 'Print',
    },

    // Attempts to pay an invoice.
    payments: {
        title: 'Payments',
        description: 'Every attempt to pay this invoice.',
        empty: 'No payment has been made for this invoice.',
        started: 'Started {date}',
        paidOn: 'Paid {date}',
        reference: 'Reference',
        reason: 'Reason given: {reason}',
    },

    // Paying an invoice.
    pay: {
        title: 'Pay this invoice',
        description: 'You are taken to the payment provider and brought back here afterwards. The invoice counts as paid once the provider confirms the payment to us.',
        amount: 'Amount to pay',
        with: 'Pay with {gateway}',
        noGateway: {
            title: 'No online payment method is set up',
            body: 'This invoice cannot be paid online at the moment.',
            noOffline: 'No other way to pay has been published yet either.',
        },
        offline: {
            title: 'Other ways to pay',
            note: 'A payment made this way is recorded by the support team once it has arrived. That settles the invoice.',
        },
        expired: {
            title: 'This offer has run out',
            body: 'The credit on this invoice was worked out for the day it was issued, so it could only be paid until {date}. Choose the plan again to get a current invoice.',
            action: 'Choose the plan again',
        },
        viewOnly: 'This invoice is waiting for payment. Someone whose role manages billing can pay it.',
    },
};
