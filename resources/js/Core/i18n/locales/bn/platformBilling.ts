import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the invoice pages of the Super Admin panel.
 *
 * The panel is English-only for now; this translation is ready for when it
 * follows the person's language. Bengali has no separate plural form, so
 * `one` and `other` read the same; both keys stay because the code picks
 * between them.
 */
const platformBilling: MessageSchema['platformBilling'] = {
    // Every invoice the platform has issued.
    invoices: {
        title: 'ইনভয়েস',
        description: 'প্ল্যাটফর্ম থেকে সব ওয়ার্কস্পেসের নামে তৈরি করা প্রতিটি ইনভয়েস।',
        caption: 'সব ওয়ার্কস্পেসের ইনভয়েস',
        search: 'ইনভয়েস খুঁজুন',
        searchPlaceholder: 'ইনভয়েস নম্বর বা ওয়ার্কস্পেস',
        statusFilter: 'অবস্থা অনুযায়ী ফিল্টার করুন',
        allStatuses: 'সব অবস্থা',
        found: '{count}টি পাওয়া গেছে',
        total: {
            one: '{count}টি ইনভয়েস',
            other: '{count}টি ইনভয়েস',
        },
        columns: {
            number: 'ইনভয়েস',
            workspace: 'ওয়ার্কস্পেস',
            kind: 'কীসের জন্য',
            issued: 'তৈরির তারিখ',
            due: 'পরিশোধের শেষ তারিখ',
            total: 'মোট',
            status: 'অবস্থা',
        },
        open: '{number} ইনভয়েস খুলুন',
        workspaceGone: 'মুছে ফেলা ওয়ার্কস্পেস',
        totals: {
            open: 'অপরিশোধিত',
            openCount: {
                one: '{count}টি ইনভয়েস পেমেন্টের অপেক্ষায়',
                other: '{count}টি ইনভয়েস পেমেন্টের অপেক্ষায়',
            },
            openNone: 'কোনো ইনভয়েস পেমেন্টের অপেক্ষায় নেই।',
            paid: 'এ মাসে পরিশোধিত',
            paidCount: {
                one: '{count}টি ইনভয়েস পরিশোধিত',
                other: '{count}টি ইনভয়েস পরিশোধিত',
            },
            paidNone: 'এ মাসে এখনো কিছু পরিশোধ করা হয়নি।',
        },
        empty: {
            title: 'এখনো কোনো ইনভয়েস নেই',
            body: 'কোনো ওয়ার্কস্পেস মূল্য পরিশোধ করতে হয় এমন প্ল্যান নিলে বা নবায়ন করলে এখানে ইনভয়েস দেখা যাবে।',
        },
        noMatch: {
            body: 'অন্য কোনো ইনভয়েস নম্বর বা ওয়ার্কস্পেসের নাম, অথবা ভিন্ন কোনো অবস্থা দিয়ে চেষ্টা করুন।',
            clear: 'ফিল্টার মুছুন',
        },
    },

    // One invoice, and what an operator can do to it.
    invoice: {
        back: 'সব ইনভয়েস',
        workspace: {
            title: 'ওয়ার্কস্পেস',
            open: 'ওয়ার্কস্পেস খুলুন',
            gone: 'যে ওয়ার্কস্পেসের নামে এই ইনভয়েস তৈরি করা হয়েছিল, সেটি আর নেই।',
        },
        payments: {
            description: 'এই ইনভয়েস পরিশোধের প্রতিটি চেষ্টা, অনলাইনে করা বা এখানে রেকর্ড করা।',
            bankReference: 'দেওয়া রেফারেন্স: {reference}',
            note: 'নোট: {note}',
        },
        unpaid: {
            title: 'পেমেন্টের অপেক্ষায়',
            body: 'ওয়ার্কস্পেস এই ইনভয়েসটি অনলাইনে পরিশোধ করতে পারে। অন্য কোনোভাবে টাকা এলে তা এখানে রেকর্ড করা হয়।',
            expired: 'প্ল্যান পরিবর্তনের এই প্রস্তাবটির মেয়াদ শেষ হয়ে গেছে, তাই এর জন্য আর পেমেন্ট রেকর্ড করা যাবে না। এটি শুধু প্রত্যাহার করা যাবে।',
            viewOnly: 'আপনার ভূমিকায় ইনভয়েস দেখা যায়, কিন্তু পেমেন্ট রেকর্ড করা বা ইনভয়েস প্রত্যাহার করা যায় না।',
        },
        record: {
            action: 'পেমেন্ট রেকর্ড করুন',
            title: 'পেমেন্ট রেকর্ড করুন',
            description: 'পেমেন্ট গেটওয়ের বাইরে পাওয়া টাকার জন্য। রেকর্ড করলে {number} ইনভয়েসটি পরিশোধিত হয়ে যাবে এবং যার জন্য এই পেমেন্ট, তা শুরু হবে।',
            amount: 'প্রাপ্ত পরিমাণ',
            amountHint: 'একটি ইনভয়েস সব সময় একটি পেমেন্টেই পুরোটা পরিশোধ করা হয়।',
            method: 'যেভাবে পরিশোধ করা হয়েছে',
            methodPlaceholder: 'একটি পদ্ধতি বেছে নিন',
            reference: 'রেফারেন্স',
            referenceHint: 'ব্যাংক লেনদেনের আইডি, চেক নম্বর বা রসিদ নম্বর।',
            note: 'নোট',
            noteHint: 'পেমেন্টের সঙ্গে রাখা হয়। ওয়ার্কস্পেস এটি দেখতে পায় না।',
            paidAt: 'প্রাপ্তির তারিখ',
            paidAtHint: 'ওয়ার্কস্পেসের টাইম জোন অনুযায়ী একটি দিন হিসেবে ধরা হয়।',
            submit: 'পেমেন্ট রেকর্ড করুন',
        },
        withdraw: {
            action: 'ইনভয়েস প্রত্যাহার করুন',
            title: '{number} ইনভয়েসটি প্রত্যাহার করবেন?',
            body: 'ওয়ার্কস্পেসকে আর এটি পরিশোধ করতে বলা হবে না। এটি আর ফেরানো যাবে না। এরপরও কিছু পাওনা থাকলে পরের বিলিং রান নতুন একটি ইনভয়েস তৈরি করবে।',
            reason: 'কারণ',
            reasonHint: 'কমপক্ষে পাঁচ অক্ষর। ওয়ার্কস্পেসের অডিট লগে রেকর্ড করা হয়।',
            confirm: 'ইনভয়েস প্রত্যাহার করুন',
        },
    },
};

export default platformBilling;
