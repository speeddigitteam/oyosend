import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the `central` pages: the public page at the root domain.
 */
const central: MessageSchema['central'] = {
    welcome: {
        title: 'আপনার ওয়ার্কস্পেস খুঁজে নিন',
        headline: 'আপনার কাজের একটি ঠিকানা আছে।',
        lede: 'Oyosend একটি প্রতিষ্ঠান ও তার টিমকে নিজস্ব একটি ওয়ার্কস্পেস দেয়: সাইন ইন করার একটিই ঠিকানা, আর এমন ডেটা যা অন্য কোনো প্রতিষ্ঠান দেখতে পায় না।',
        find: {
            title: 'আপনার ওয়ার্কস্পেস খুঁজে নিন',
            label: 'ওয়ার্কস্পেসের ঠিকানা',
            // An example of the value itself; workspace addresses are Latin letters, digits and hyphens.
            placeholder: 'your-company',
            hint: '.{domain}-এর আগের অংশটুকু। চাইলে পুরো ঠিকানাটিও পেস্ট করতে পারেন।',
            submit: 'সাইন ইন পৃষ্ঠায় যান',
        },
        today: {
            label: 'আজ এখানে যা আছে',
            isolation: {
                title: 'আলাদা আলাদা ওয়ার্কস্পেস',
                body: 'প্রতিটি প্রতিষ্ঠানের সদস্য, ভূমিকা ও রেকর্ড থাকে তার নিজের ওয়ার্কস্পেসে, অন্য সবার থেকে আলাদা।',
            },
            languages: {
                title: 'English ও বাংলা',
                body: 'প্রত্যেকে নিজের ভাষা বেছে নেন, আর সংখ্যা ইংরেজি না বাংলা অঙ্কে দেখাবে তা-ও।',
            },
            roles: {
                title: 'ভূমিকা ও অনুমতি',
                body: 'কার কতটুকু অ্যাক্সেস, তা ঠিক হয় তাঁর ভূমিকা দিয়ে। নিজের নেই এমন কোনো অনুমতি কেউ অন্যকে দিতে পারেন না।',
            },
            security: {
                title: 'দুই-ধাপ যাচাইকরণ ও অডিট লগ',
                body: 'সাইন ইনের সময় অথেনটিকেটর অ্যাপের কোড বাধ্যতামূলক করা যায়। সাইন ইন, আমন্ত্রণ ও ভূমিকা পরিবর্তন রেকর্ড করা হয়।',
            },
        },
    },
};

export default central;
