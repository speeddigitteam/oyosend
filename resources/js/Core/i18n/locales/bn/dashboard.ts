import type { MessageSchema } from '@/Core/i18n';

const dashboard: MessageSchema['dashboard'] = {
    title: 'ড্যাশবোর্ড',
    greeting: {
        morning: 'শুভ সকাল, {name}',
        afternoon: 'শুভ অপরাহ্ণ, {name}',
        evening: 'শুভ সন্ধ্যা, {name}',
    },
    subtitle: '{workspace} আজ কোন অবস্থায় আছে, এখানে দেখে নিন।',
    team: {
        title: 'টিম',
        members: 'সক্রিয় সদস্য',
        pendingInvitations: 'অপেক্ষমাণ আমন্ত্রণ',
        roles: 'ভূমিকা',
        joinedThisMonth: 'এ মাসে যোগ দিয়েছেন',
        suspended: '{count} জন স্থগিত',
        recent: 'সম্প্রতি যোগ দিয়েছেন',
        manage: 'সদস্য পরিচালনা করুন',
        // {when} is a relative time: "৩ দিন আগে যোগ দিয়েছেন".
        joined: '{when} যোগ দিয়েছেন',
    },
    security: {
        title: 'আপনার অ্যাকাউন্ট',
        twoFactor: 'দুই-ধাপ যাচাইকরণ',
        twoFactorOn: 'চালু',
        twoFactorOff: 'বন্ধ',
        twoFactorPrompt: 'দ্বিতীয় একটি ধাপ যোগ করুন, যাতে পাসওয়ার্ড চুরি হলেও শুধু তা দিয়ে কেউ ঢুকতে না পারে।',
        recoveryCodes: '{count}টি রিকভারি কোড বাকি আছে',
        recoveryLow: 'শিগগিরই নতুন রিকভারি কোড তৈরি করে নিন',
        lastLogin: 'সর্বশেষ সাইন ইন',
        review: 'নিরাপত্তা পর্যালোচনা করুন',
        enable: 'চালু করুন',
    },
    activity: {
        title: 'সাম্প্রতিক কার্যকলাপ',
        empty: 'এই ওয়ার্কস্পেসের কার্যকলাপ এখানে দেখা যাবে।',
        viewAll: 'অডিট লগ খুলুন',
        // Bengali event labels already carry the verb ("সাইন ইন করেছেন"), so the
        // actor column needs no word for "by" - the name alone reads correctly.
        by: '{actor}',
    },
    apps: {
        title: 'অ্যাপ',
        empty: 'এখনো কোনো অ্যাপ ইনস্টল করা হয়নি',
        emptyBody: 'অ্যাপ স্টোর চালু হলে সেখান থেকে CRM, প্রজেক্ট, HRM, ইনভয়েসিং ও অন্যান্য অ্যাপ ইনস্টল করা যাবে।',
    },
};

export default dashboard;
