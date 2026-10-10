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
        },
        free: 'Free',
        unlimited: 'Unlimited',
        notOffered: 'Not offered',
        trialDays: '{count}-day free trial',
    },
};
