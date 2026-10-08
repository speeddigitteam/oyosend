import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the `audit` pages.
 */
const audit: MessageSchema['audit'] = {
    title: 'অডিট লগ',
    description: '{workspace} ওয়ার্কস্পেসে কে কী করেছেন তার রেকর্ড। এন্ট্রিগুলো সম্পাদনা করা বা মুছে ফেলা যায় না।',
    caption: 'এই ওয়ার্কস্পেসের কার্যকলাপ, নতুনগুলো আগে',
    order: 'নতুনগুলো আগে',
    orderFiltered: 'ফিল্টার করা, নতুনগুলো আগে',
    filters: {
        label: 'অডিট লগ ফিল্টার করুন',
        search: 'অনুসন্ধান',
        searchPlaceholder: 'কোনো ব্যক্তি বা রেকর্ড',
        family: 'কার্যকলাপ',
        allFamilies: 'সব কার্যকলাপ',
        from: 'শুরুর তারিখ',
        to: 'শেষের তারিখ',
        apply: 'প্রয়োগ করুন',
        clear: 'ফিল্টার মুছুন',
    },
    columns: {
        time: 'সময়',
        event: 'কার্যকলাপ',
        actor: 'যিনি করেছেন',
        subject: 'সংশ্লিষ্ট',
        ip: 'IP ঠিকানা',
    },
    details: {
        toggle: 'বিস্তারিত: {event}',
        // Label of a change the server recorded without naming a field.
        change: 'পরিবর্তন',
        value: 'বিস্তারিত',
        // Read out between the old and the new value: "সক্রিয় থেকে পরিবর্তিত হয়ে স্থগিত".
        becomes: 'থেকে পরিবর্তিত হয়ে',
        none: 'নেই',
        redacted: 'গোপন',
        yes: 'হ্যাঁ',
        no: 'না',
    },
    // Names of the fields that show up in entry details. Anything not listed
    // here is shown by its own name with the underscores taken out.
    fields: {
        name: 'নাম',
        description: 'বিবরণ',
        email: 'ইমেইল',
        status: 'অবস্থা',
        role: 'ভূমিকা',
        role_id: 'ভূমিকার আইডি',
        user_id: 'ব্যবহারকারীর আইডি',
        tenant_id: 'ওয়ার্কস্পেসের আইডি',
        guard_name: 'গার্ড',
        is_owner: 'ওয়ার্কস্পেসের মালিক',
        is_system: 'সিস্টেম ভূমিকা',
        joined_at: 'যোগ দিয়েছেন',
        expires_at: 'মেয়াদ শেষ',
        invited_by: 'আমন্ত্রণ জানিয়েছেন',
        locale: 'ভাষা',
        timezone: 'টাইম জোন',
        added: 'যোগ হয়েছে',
        removed: 'সরানো হয়েছে',
    },
    empty: {
        title: 'এখনো কোনো কার্যকলাপ নেই',
        body: 'এই ওয়ার্কস্পেসে সাইন ইন, আমন্ত্রণ, ভূমিকা পরিবর্তন ও অন্যান্য কাজ ঘটার সঙ্গে সঙ্গে এখানে রেকর্ড হয়।',
        filteredTitle: 'এই ফিল্টারগুলোর সঙ্গে কিছুই মিলছে না',
        filteredBody: 'তারিখের পরিসর বাড়িয়ে দেখুন, অথবা অন্য কাউকে খুঁজে দেখুন।',
    },
};

export default audit;
