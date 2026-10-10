import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the workspace's own pages - settings, first-run setup, the
 * "workspace locked" screen - and for the notices the workspace layout shows
 * on every page (support session, subscription).
 */
const workspace: MessageSchema['workspace'] = {
    // Groups of fields, shared by the settings page and first-run setup.
    sections: {
        company: {
            title: 'প্রতিষ্ঠান',
            description: 'এই ওয়ার্কস্পেসটি যে প্রতিষ্ঠানের।',
        },
        contact: {
            title: 'যোগাযোগের তথ্য',
            description: 'প্রতিষ্ঠানের সঙ্গে যেভাবে যোগাযোগ করা যায়।',
        },
        address: {
            title: 'ঠিকানা',
            description: 'প্রতিষ্ঠানের নিবন্ধিত বা প্রধান কার্যালয়।',
        },
        regional: {
            title: 'আঞ্চলিক সেটিংস',
            description: 'ওয়ার্কস্পেসের সবাইকে তারিখ, সময় ও অর্থের পরিমাণ যেভাবে দেখানো হয়।',
        },
    },
    fields: {
        name: 'ওয়ার্কস্পেসের নাম',
        nameHint: 'আপনার টিম সাইডবারে ও ইমেইলে এই নামটি দেখে।',
        legalName: 'নিবন্ধিত নাম',
        legalNameHint: 'প্রতিষ্ঠানের নিবন্ধিত নাম, যদি তা আলাদা হয়।',
        taxId: 'ট্যাক্স আইডি',
        taxIdHint: 'যেমন BIN, TIN বা VAT নম্বর।',
        email: 'ইমেইল',
        phone: 'ফোন',
        website: 'ওয়েবসাইট',
        websiteHint: 'পুরো ঠিকানা, শুরুতে http:// বা https:// সহ',
        street: 'বিস্তারিত ঠিকানা',
        city: 'শহর',
        postalCode: 'পোস্টাল কোড',
        country: 'দেশ',
        noCountry: 'নির্ধারণ করা হয়নি',
        language: 'ভাষা',
        languageHint: 'যাঁরা নিজের জন্য কোনো ভাষা বেছে নেননি, তাঁদের জন্য।',
        timezone: 'টাইম জোন',
        currency: 'মুদ্রা',
        preview: {
            title: 'নমুনা',
            time: 'সেখানে এখন সময়',
            money: 'একটি পরিমাণ',
        },
    },
    settings: {
        title: 'ওয়ার্কস্পেস সেটিংস',
        description: '{workspace} ওয়ার্কস্পেসের প্রতিষ্ঠানের প্রোফাইল, আঞ্চলিক সেটিংস ও লোগো।',
        unsaved: 'আপনার কিছু পরিবর্তন এখনো সংরক্ষণ করা হয়নি।',
        logo: {
            title: 'লোগো',
            description: '{workspace} ওয়ার্কস্পেসের সবাইকে সাইডবারে এটি দেখানো হয়।',
            alt: '{workspace} ওয়ার্কস্পেসের লোগো',
            empty: 'এখনো কোনো লোগো নেই। সাইডবারে ওয়ার্কস্পেসের নামের আদ্যক্ষর দেখানো হচ্ছে।',
            requirements: 'PNG, JPG বা WebP ফাইল; আকার সর্বোচ্চ এক মেগাবাইট, আর দৈর্ঘ্য ও প্রস্থ সর্বোচ্চ দুই হাজার পিক্সেল।',
            upload: 'লোগো আপলোড করুন',
            replace: 'পরিবর্তন করুন',
            tooLarge: 'ফাইলটি এক মেগাবাইটের চেয়ে বড়। ছোট একটি ফাইল বেছে নিন।',
            wrongType: 'PNG, JPG বা WebP ছবি বেছে নিন।',
            removeDialog: {
                title: 'লোগো সরিয়ে দেবেন?',
                body: 'সাইডবারে আবার {workspace} ওয়ার্কস্পেসের নামের আদ্যক্ষর দেখানো হবে, আর ফাইলটি মুছে ফেলা হবে।',
                confirm: 'লোগো সরিয়ে দিন',
            },
        },
        url: {
            title: 'ওয়ার্কস্পেসের ঠিকানা',
            description: 'আপনার টিম এখানে সাইন ইন করে।',
            hint: 'এই ঠিকানা পরিবর্তন করা যায় না: লিংক, বুকমার্ক ও সাইন ইন সবই এর ওপর নির্ভর করে।',
            copy: 'ঠিকানা কপি করুন',
            copied: 'ঠিকানা কপি হয়েছে',
        },
    },
    onboarding: {
        eyebrow: 'প্রথমবারের সেটআপ',
        title: '{workspace} সেট আপ করুন',
        description: 'শুরু করার আগে কয়েকটি তথ্য মিলিয়ে নিন। এখানকার সবকিছু পরে ওয়ার্কস্পেস সেটিংস থেকে পরিবর্তন করা যাবে।',
        deviceTimezone: 'আপনার ডিভাইসের টাইম জোন {timezone}।',
        useDeviceTimezone: 'এটি ব্যবহার করুন',
        later: 'ঠিকানা, ট্যাক্স আইডি ও লোগো পরে ওয়ার্কস্পেস সেটিংস থেকে যোগ করা যাবে।',
        submit: 'সেটআপ শেষ করুন',
    },
    // The screen a member gets when the subscription has ended.
    locked: {
        title: '{workspace} লক করা আছে',
        expired: 'পেমেন্ট না পাওয়ায় এর সাবস্ক্রিপশনের মেয়াদ শেষ হয়েছে, তাই ওয়ার্কস্পেসটি আপাতত বন্ধ আছে।',
        canceled: 'এর সাবস্ক্রিপশন বাতিল করা হয়েছে, তাই ওয়ার্কস্পেসটি আপাতত বন্ধ আছে।',
        safe: 'কিছুই মুছে ফেলা হয়নি। ওয়ার্কস্পেসের মালিক বিলিং থেকে অ্যাক্সেস আবার চালু করতে পারবেন।',
        signedInAs: '{email} হিসেবে সাইন ইন করা আছে',
        account: 'অ্যাকাউন্ট সেটিংস',
    },
    // The subscription notice at the top of every page, shown only when something needs attention.
    notice: {
        label: 'সাবস্ক্রিপশনের অবস্থা',
        billing: 'বিলিং পৃষ্ঠায় যান',
        trialEnding: '{plan} প্ল্যানের ট্রায়াল {date} তারিখে শেষ হবে।',
        trialEnded: '{plan} প্ল্যানের ট্রায়াল শেষ হয়েছে।',
        pastDue: '{plan} প্ল্যানের একটি পেমেন্ট বকেয়া আছে। পরিশোধ করা না হলে {date} থেকে ওয়ার্কস্পেসটি লক করা থাকবে।',
        pastDueUndated: '{plan} প্ল্যানের একটি পেমেন্ট বকেয়া আছে।',
        ending: '{plan} প্ল্যানটি {date} তারিখে শেষ হবে। এরপর ওয়ার্কস্পেসটি লক করা থাকবে।',
        locked: '{plan} প্ল্যানের মেয়াদ শেষ হওয়ায় এই ওয়ার্কস্পেসটি লক করা আছে।',
        lockedOwner: 'আবার চালু করতে বিলিং থেকে একটি প্ল্যান বেছে নিন।',
        lockedMember: 'ওয়ার্কস্পেসের মালিক বিলিং থেকে এটি আবার চালু করতে পারবেন।',
        tellOwner: 'ওয়ার্কস্পেসের মালিককে বিষয়টি জানান।',
    },
    // The banner shown on every page while a Super Admin is signed in as the owner.
    support: {
        title: 'সাপোর্ট সেশন',
        by: 'সেশনটি খুলেছেন {by}',
        ends: '{time} পর্যন্ত চলবে',
        audited: 'আপনি ওয়ার্কস্পেসের মালিক হিসেবে সাইন ইন করে আছেন। এখানে যা করা হবে তার সবই অডিট লগে রেকর্ড করা হবে।',
        end: 'সেশন শেষ করুন',
    },
};

export default workspace;
