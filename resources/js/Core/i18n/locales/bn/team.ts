import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the `team` pages.
 *
 * Bengali has no separate plural form, so `one` and `other` read the same;
 * both keys stay because the code picks between them.
 */
const team: MessageSchema['team'] = {
    members: {
        title: 'সদস্য',
        description: '{workspace} ওয়ার্কস্পেসে যাঁরা সাইন ইন করতে পারেন তাঁদের সবাই, সঙ্গে তাঁদের ভূমিকা — যা ঠিক করে সেখানে কে কী করতে পারবেন।',
        invite: 'সদস্যকে আমন্ত্রণ জানান',
        caption: 'এই ওয়ার্কস্পেসের সদস্যরা',
        search: 'নাম বা ইমেইল দিয়ে খুঁজুন',
        clearSearch: 'অনুসন্ধান মুছুন',
        people: {
            one: '{count} জন',
            other: '{count} জন',
        },
        columns: {
            member: 'নাম',
            role: 'ভূমিকা',
            branch: 'শাখা',
            department: 'বিভাগ',
            status: 'অবস্থা',
            twoFactor: 'দুই-ধাপ',
            joined: 'যোগ দিয়েছেন',
            lastSeen: 'সর্বশেষ সক্রিয়',
            actions: 'অ্যাকশন',
        },
        owner: 'মালিক',
        noRole: 'কোনো ভূমিকা নেই',
        twoFactorOn: 'দুই-ধাপ যাচাইকরণ চালু আছে',
        twoFactorOff: 'দুই-ধাপ যাচাইকরণ বন্ধ আছে',
        actions: {
            menu: '{name}-এর জন্য অ্যাকশন',
            changeRole: 'ভূমিকা পরিবর্তন করুন',
            place: 'শাখা ও বিভাগ',
            suspend: 'স্থগিত করুন',
            reactivate: 'আবার সক্রিয় করুন',
            remove: 'ওয়ার্কস্পেস থেকে সরিয়ে দিন',
        },
        // Shown instead of the placement action while the workspace has nothing to place people in.
        organize: {
            body: 'এই ওয়ার্কস্পেসে এখনো কোনো শাখা বা বিভাগ নেই। যোগ করা হলে এখান থেকেই সদস্যদের সেগুলোতে রাখতে পারবেন।',
            link: 'শাখা ও বিভাগ সেট আপ করুন',
        },
        empty: {
            title: 'এখনো কোনো সদস্য নেই',
            body: 'আমন্ত্রণ গ্রহণ করলেই সদস্যদের এখানে দেখা যাবে।',
            searchTitle: '“{query}”-এর সঙ্গে কেউ মিলছে না',
            searchBody: 'বানান ঠিক আছে কি না দেখুন, অথবা নামের বদলে ইমেইল ঠিকানা দিয়ে খুঁজে দেখুন।',
        },
    },
    changeRole: {
        title: '{name}-এর ভূমিকা পরিবর্তন করুন',
        description: 'সংরক্ষণ করার সঙ্গে সঙ্গেই তাঁর অ্যাক্সেস বদলে যাবে।',
        current: 'বর্তমান ভূমিকা',
        role: 'নতুন ভূমিকা',
        placeholder: 'একটি ভূমিকা বেছে নিন',
        hint: 'যে ভূমিকার সব অনুমতি আপনার নিজের আছে, সেই ভূমিকাই আপনি দিতে পারবেন।',
        submit: 'ভূমিকা পরিবর্তন করুন',
    },
    placement: {
        title: '{name}-এর শাখা ও বিভাগ',
        description: 'কেউ কোন শাখা ও বিভাগে আছেন, তা-ই ঠিক করে শাখা বা বিভাগে সীমিত কোনো অনুমতি দিয়ে তিনি কোন রেকর্ডগুলোতে অ্যাক্সেস পাবেন।',
        branch: 'শাখা',
        department: 'বিভাগ',
        noBranch: 'কোনো শাখা নেই',
        noDepartment: 'কোনো বিভাগ নেই',
        missing: 'যেটি দরকার সেটি তালিকায় নেই?',
        manage: 'শাখা ও বিভাগ পৃষ্ঠায় গিয়ে যোগ করুন',
    },
    suspend: {
        title: '{name}-কে স্থগিত করবেন?',
        body: 'কেউ আবার সক্রিয় না করা পর্যন্ত {name} {workspace} ওয়ার্কস্পেসে ঢুকতে পারবেন না। তাঁর ভূমিকা ও আগের কার্যকলাপ যেমন আছে তেমনই থাকবে।',
        confirm: 'স্থগিত করুন',
    },
    remove: {
        title: '{name}-কে সরিয়ে দেবেন?',
        body: '{name} ({email}) সঙ্গে সঙ্গেই {workspace} ওয়ার্কস্পেসের অ্যাক্সেস এবং এখানকার ভূমিকা হারাবেন। পরে চাইলে তাঁকে আবার আমন্ত্রণ জানাতে পারবেন।',
        confirm: 'সদস্যকে সরিয়ে দিন',
    },
    invite: {
        title: 'একজন সদস্যকে আমন্ত্রণ জানান',
        description: '{workspace} ওয়ার্কস্পেসে যোগ দেওয়ার লিংকসহ একটি ইমেইল তাঁর কাছে যাবে।',
        email: 'ইমেইল ঠিকানা',
        role: 'ভূমিকা',
        rolePlaceholder: 'একটি ভূমিকা বেছে নিন',
        roleHint: 'যে ভূমিকার সব অনুমতি আপনার নিজের আছে, সেই ভূমিকাই আপনি দিতে পারবেন।',
        submit: 'আমন্ত্রণ পাঠান',
        noRoles: 'এই ওয়ার্কস্পেসের প্রতিটি ভূমিকায় এমন কিছু অনুমতি আছে যা আপনার নিজের নেই, তাই আপনি এগুলোর কোনোটিই দিতে পারবেন না। আমন্ত্রণটি পাঠাতে কোনো অ্যাডমিনকে বলুন।',
    },
    invitations: {
        title: 'অপেক্ষমাণ আমন্ত্রণ',
        description: 'আমন্ত্রণ পেয়েছেন, কিন্তু এখনো যোগ দেননি।',
        invitedAs: '{role} হিসেবে আমন্ত্রিত',
        invitedAsBy: '{inviter} {role} হিসেবে আমন্ত্রণ জানিয়েছেন',
        // {when} is a relative time: "৬ দিনের মধ্যে মেয়াদ শেষ হবে".
        expires: '{when} মেয়াদ শেষ হবে',
        expiredOn: '{date} তারিখে মেয়াদ শেষ হয়েছে',
        withdraw: 'প্রত্যাহার করুন',
        withdrawFor: '{email}-এর আমন্ত্রণ প্রত্যাহার করুন',
        resend: 'আবার পাঠান',
        resendFor: '{email} ঠিকানায় আবার পাঠান',
        empty: 'কোনো অপেক্ষমাণ আমন্ত্রণ নেই',
        emptyBody: 'আপনার পাঠানো আমন্ত্রণগুলো গ্রহণ বা প্রত্যাহার না করা পর্যন্ত এখানে থাকবে।',
        confirm: {
            title: 'এই আমন্ত্রণটি প্রত্যাহার করবেন?',
            body: '{email} ঠিকানায় পাঠানো লিংকটি আর কাজ করবে না। চাইলে যেকোনো সময় তাঁকে আবার আমন্ত্রণ জানাতে পারবেন।',
            submit: 'আমন্ত্রণ প্রত্যাহার করুন',
        },
    },
};

export default team;
