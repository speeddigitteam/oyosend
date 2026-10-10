import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the platform's audit log in the Super Admin panel. The words
 * for an entry's details (`audit.details`, `audit.fields`) are shared with a
 * workspace's audit log.
 *
 * Event names (`plan.updated`) are code and stay as they are.
 */
const platformAudit: MessageSchema['platformAudit'] = {
    description:
        'প্ল্যাটফর্ম পর্যায়ে যা ঘটেছে, এবং কোনো অ্যাডমিন কোনো ওয়ার্কস্পেসের ভেতরে যা যা করেছেন। ওয়ার্কস্পেসের নিজস্ব সদস্যরা যা করেন তা সেই ওয়ার্কস্পেসের অডিট লগেই থাকে। এন্ট্রিগুলো সম্পাদনা করা বা মুছে ফেলা যায় না।',
    caption: 'প্ল্যাটফর্মের কার্যকলাপ, নতুনগুলো আগে',
    order: 'নতুনগুলো আগে',
    orderFiltered: 'অনুসন্ধানের ফলাফল, নতুনগুলো আগে',
    search: 'অডিট লগে খুঁজুন',
    searchPlaceholder: 'কোনো ইমেইল, রেকর্ড বা ঘটনার নাম',
    searchHint: 'ঘটনা খুঁজতে হয় প্রতিটি কার্যকলাপের নিচে লেখা নাম দিয়ে, যেমন plan.updated।',
    columns: {
        time: 'সময়',
        event: 'কার্যকলাপ',
        actor: 'যিনি করেছেন',
        subject: 'সংশ্লিষ্ট',
        workspace: 'ওয়ার্কস্পেস',
        ip: 'IP ঠিকানা',
    },
    // In the Workspace column of an entry that belongs to no workspace.
    platformLevel: 'প্ল্যাটফর্ম',
    empty: {
        title: 'এখনো কোনো কার্যকলাপ নেই',
        body: 'এই প্যানেলে সাইন ইন, প্ল্যান ও সেটিংসের পরিবর্তন, এবং ওয়ার্কস্পেসের ভেতরে অ্যাডমিনদের কাজ ঘটার সঙ্গে সঙ্গে এখানে রেকর্ড হয়।',
        filteredTitle: '“{query}”-এর সঙ্গে কিছুই মিলছে না',
        filteredBody: 'কোনো ইমেইল ঠিকানা দিয়ে খুঁজে দেখুন, অথবা ঘটনার নাম দিয়ে, যেমন platform.workspace.suspended।',
    },
};

export default platformAudit;
