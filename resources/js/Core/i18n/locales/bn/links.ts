import type { MessageSchema } from '@/Core/i18n';

const links: MessageSchema['links'] = {
    eyebrow: 'লিংক',
    title: 'লিংক',
    description: 'আপনার টিম বারবার যে ওয়েবসাইট, টুল, ইমেইল ঠিকানা ও ডকুমেন্ট ব্যবহার করে।',
    search: 'লিংক খুঁজুন',
    count: {
        one: '{count}টি লিংক',
        other: '{count}টি লিংক',
    },
    kinds: {
        website: 'ওয়েবসাইট',
        tool: 'টুল',
        email: 'ইমেইল ঠিকানা',
        document: 'ডকুমেন্ট',
        other: 'অন্যান্য',
    },
    filters: {
        kind: 'ধরন অনুযায়ী ফিল্টার',
        all: 'সব',
        folder: 'ফোল্ডার অনুযায়ী ফিল্টার',
        allFolders: 'সব ফোল্ডার',
    },
    actions: {
        add: 'লিংক যোগ করুন',
    },
    empty: {
        title: 'এখনো কোনো লিংক নেই',
        body: 'প্রতিদিন যে ওয়েবসাইট, টুল ও ইমেইল ঠিকানা ব্যবহার করেন সেগুলো সেভ করুন, আর এক জায়গায় আবার খুঁজে পান।',
        viewOnly: 'আপনার টিমের সেভ করা লিংক এখানে দেখা যাবে।',
    },
    sections: {
        pinned: 'পিন করা',
        others: 'অন্যান্য',
    },
    card: {
        copy: 'ঠিকানা কপি করুন',
        copied: 'ঠিকানা কপি হয়েছে।',
        pin: 'লিংক পিন করুন',
        unpin: 'লিংক আনপিন করুন',
        pinned: 'পিন করা',
        edit: 'লিংক সম্পাদনা',
        delete: 'লিংক মুছুন',
        opensNewTab: '(নতুন ট্যাবে খুলবে)',
        showFolder: '{folder} ফোল্ডারের লিংক দেখুন',
    },
    field: {
        kind: 'ধরন',
        title: 'নাম',
        url: 'ওয়েব ঠিকানা',
        urlHint: 'https:// বা http:// দিয়ে শুরু হবে',
        urlPlaceholder: 'https://',
        email: 'ইমেইল ঠিকানা',
        description: 'বিবরণ',
        folder: 'ফোল্ডার',
        folderHint: 'নতুন ফোল্ডারের নাম লিখুন বা আগের একটি বেছে নিন।',
        owner: 'মালিক',
        pinned: 'উপরে পিন করে রাখুন',
    },
    form: {
        createHeading: 'লিংক যোগ করুন',
        editHeading: 'লিংক সম্পাদনা',
        hint: 'এখানে পাসওয়ার্ড বা অন্য কোনো গোপন তথ্য রাখবেন না; সেগুলোর জন্য পাসওয়ার্ড ম্যানেজার ব্যবহার করুন।',
    },
    remove: {
        heading: 'লিংক মুছুন',
        body: 'এই লিংকটি স্থায়ীভাবে মুছে যাবে। এটি আর ফেরানো যাবে না।',
    },
    common: {
        save: 'সংরক্ষণ করুন',
        cancel: 'বাতিল',
        clear: 'ফিল্টার মুছুন',
        unassigned: 'কাউকে দেওয়া হয়নি',
        noMatches: 'কোনো লিংক মেলেনি',
        noMatchesBody: 'অন্য কোনো শব্দ, ধরন বা ফোল্ডার দিয়ে চেষ্টা করুন।',
    },
};

export default links;
