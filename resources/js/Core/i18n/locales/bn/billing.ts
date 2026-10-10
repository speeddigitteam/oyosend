import type { MessageSchema } from '@/Core/i18n';

const billing: MessageSchema['billing'] = {
    /*
     * The vocabulary of billing, shared by the workspace's billing pages and
     * the Super Admin panel. Keys under `subscriptionStatus`, `invoiceStatus`,
     * `invoiceKind`, `paymentStatus`, `interval` and `method` are the values
     * the server sends.
     */
    terms: {
        interval: {
            monthly: 'মাসিক',
            yearly: 'বার্ষিক',
        },
        perInterval: {
            monthly: 'প্রতি মাসে',
            yearly: 'প্রতি বছরে',
        },
        subscriptionStatus: {
            trialing: 'ট্রায়াল',
            active: 'সক্রিয়',
            past_due: 'পেমেন্ট বকেয়া',
            expired: 'মেয়াদোত্তীর্ণ',
            canceled: 'বাতিল',
            none: 'কোনো প্ল্যান নেই',
        },
        invoiceStatus: {
            open: 'অপরিশোধিত',
            paid: 'পরিশোধিত',
            void: 'প্রত্যাহার করা',
            overdue: 'বকেয়া',
        },
        invoiceKind: {
            subscription: 'নতুন প্ল্যান',
            renewal: 'নবায়ন',
            change: 'প্ল্যান পরিবর্তন',
        },
        paymentStatus: {
            pending: 'অপেক্ষমাণ',
            succeeded: 'পরিশোধিত',
            failed: 'ব্যর্থ',
            canceled: 'বাতিল',
        },
        // Online gateways show their own name; this is money recorded by an operator.
        offlineGateway: 'সাপোর্ট টিমের রেকর্ড করা',
        method: {
            bank_transfer: 'ব্যাংক ট্রান্সফার',
            cash: 'নগদ',
            cheque: 'চেক',
            mobile_banking: 'মোবাইল ব্যাংকিং',
            other: 'অন্যান্য',
        },
        // Invoice lines, by their `type`.
        line: {
            plan: '{plan} প্ল্যান, {interval}',
            credit: 'আগের প্ল্যানের অব্যবহৃত সময়ের ক্রেডিট',
        },
        free: 'বিনামূল্যে',
        unlimited: 'সীমাহীন',
        notOffered: 'পাওয়া যায় না',
        trialDays: '{count} দিনের বিনামূল্যে ট্রায়াল',
    },
};

export default billing;
