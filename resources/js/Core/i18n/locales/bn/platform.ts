import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the `platform` pages (the Super Admin panel).
 *
 * The English file notes that the panel is English-only for now; this
 * translation is ready for when the panel follows the person's language.
 */
const platform: MessageSchema['platform'] = {
    // Signed-out screens. Operators have no "remember me" and no self-service recovery.
    auth: {
        login: {
            documentTitle: 'অ্যাডমিন সাইন ইন',
            // Title and subtitle are read together: "সাইন ইন করুন / {app} অ্যাডমিন প্যানেলে".
            title: 'সাইন ইন করুন',
            subtitle: '{app} অ্যাডমিন প্যানেলে',
            email: 'ইমেইল',
            password: 'পাসওয়ার্ড',
            submit: 'সাইন ইন করুন',
            restrictedTitle: 'সীমিত অ্যাক্সেস',
            restrictedBody: 'শুধু প্ল্যাটফর্মের কর্মীদের জন্য। সাইন ইন ও ব্যর্থ চেষ্টাগুলো IP ঠিকানাসহ রেকর্ড করা হয়।',
            noReset: 'এই প্যানেলে নিজে নিজে পাসওয়ার্ড রিসেট করার ব্যবস্থা নেই।',
            notWorkspace: 'ওয়ার্কস্পেসের সদস্যরা নিজ নিজ ওয়ার্কস্পেসের ঠিকানায় সাইন ইন করেন।',
        },
        challenge: {
            title: 'দুই-ধাপ যাচাইকরণ',
            codeIntro: 'আপনার অথেনটিকেটর অ্যাপ থেকে ছয় অঙ্কের কোডটি লিখুন।',
            recoveryIntro: 'দুই-ধাপ যাচাইকরণ সেট আপ করার সময় যে রিকভারি কোডগুলো সংরক্ষণ করেছিলেন, তার একটি লিখুন।',
            code: 'যাচাইকরণ কোড',
            recoveryCode: 'রিকভারি কোড',
            recoveryHint: 'প্রতিটি রিকভারি কোড একবারই কাজ করে।',
            submit: 'যাচাই করে সাইন ইন করুন',
            useRecovery: 'এর বদলে রিকভারি কোড ব্যবহার করুন',
            useCode: 'এর বদলে অথেনটিকেটর অ্যাপের কোড ব্যবহার করুন',
            back: 'সাইন ইনে ফিরে যান',
        },
        setup: {
            title: 'দুই-ধাপ যাচাইকরণ সেট আপ করুন',
            intro: 'অ্যাডমিন প্যানেলে দুই-ধাপ যাচাইকরণ বাধ্যতামূলক। এটি চালু না করা পর্যন্ত এই অ্যাকাউন্ট দিয়ে আর কিছুই খোলা যাবে না।',
            step: 'ধাপ {number}',
            scan: 'আপনার অথেনটিকেটর অ্যাপে এই অ্যাকাউন্টটি যোগ করুন',
            confirm: 'ঠিকমতো কাজ করছে কি না দেখে নিন',
            submit: 'চালু করে এগিয়ে যান',
            recoveryNext: 'এরপর আপনাকে রিকভারি কোডগুলো দেখানো হবে, মাত্র একবার। সেগুলো রাখার জন্য নিরাপদ একটি জায়গা ঠিক করে রাখুন।',
            signedInAs: 'সাইন ইন করা অ্যাকাউন্ট',
        },
    },
    dashboard: {
        description: 'ওয়ার্কস্পেস, ব্যবহারকারী এবং যেসব সার্ভিসের ওপর প্ল্যাটফর্মটি চলে।',
        stats: {
            tenants: 'ওয়ার্কস্পেস',
            newThisMonth: 'এ মাসে {count}টি নতুন',
            noneThisMonth: 'এ মাসে নতুন একটিও নেই',
            active: 'সক্রিয়',
            activeHint: 'সদস্যদের জন্য খোলা',
            pending: 'অপেক্ষমাণ',
            pendingHint: 'নিবন্ধিত, এখনো অনুমোদিত নয়',
            suspended: 'স্থগিত',
            suspendedHint: 'অ্যাক্সেস বন্ধ, ডেটা রাখা আছে',
        },
        totals: {
            title: 'প্ল্যাটফর্মের মোট হিসাব',
            users: 'ব্যবহারকারী',
            usersHint: 'যেসব অ্যাকাউন্ট দিয়ে কোনো ওয়ার্কস্পেসে সাইন ইন করা যায়',
            memberships: 'সদস্যপদ',
            membershipsHint: 'একজন ব্যক্তি একাধিক ওয়ার্কস্পেসের সদস্য হতে পারেন',
            modules: 'মডিউল',
            modulesHint: 'এই বিল্ডে অন্তর্ভুক্ত',
        },
        recent: {
            title: 'সাম্প্রতিক ওয়ার্কস্পেস',
            caption: 'সবচেয়ে সম্প্রতি তৈরি হওয়া ওয়ার্কস্পেসগুলো',
            viewAll: 'সব ওয়ার্কস্পেস',
            subdomain: 'সাবডোমেইন',
            empty: 'এখনো কোনো ওয়ার্কস্পেস নেই',
            emptyBody: 'নতুন ওয়ার্কস্পেস তৈরি হলেই সেগুলো এখানে দেখা যাবে।',
        },
    },
    // Labels for the keys SystemHealth::checks() reports.
    health: {
        title: 'সিস্টেমের অবস্থা',
        refresh: 'আবার পরীক্ষা চালান',
        allPassing: 'সব পরীক্ষা ঠিক আছে',
        // Plural forms are separate keys in English; Bengali reads the same for one and for many.
        failing: '{total}টির মধ্যে {count}টিতে মনোযোগ প্রয়োজন',
        failingOne: '{total}টির মধ্যে {count}টিতে মনোযোগ প্রয়োজন',
        // After the name of the queue connection, and on its own for failed jobs.
        pending: '{count}টি অপেক্ষমাণ',
        failed: '{count}টি ব্যর্থ',
        checks: {
            database: 'ডেটাবেস',
            cache: 'ক্যাশ',
            queue: 'কিউ',
            failed_jobs: 'ব্যর্থ জব',
        },
    },
    activity: {
        title: 'প্যানেলের কার্যকলাপ',
        description: 'এই প্যানেলে সাইন ইন এবং প্ল্যাটফর্ম পর্যায়ের ঘটনা।',
        caption: 'অ্যাডমিন প্যানেলের সাম্প্রতিক কার্যকলাপ',
        columns: {
            event: 'ঘটনা',
            actor: 'অ্যাকাউন্ট',
            ip: 'IP ঠিকানা',
            when: 'কখন',
        },
        noActor: 'সাইন ইন করা নেই',
        empty: 'প্যানেলে এখনো কোনো কার্যকলাপ নেই',
        emptyBody: 'এই প্যানেলে সাইন ইন এবং প্ল্যাটফর্ম পর্যায়ের ঘটনা এখানে দেখা যাবে।',
    },
    tenants: {
        description: 'প্রতিটি ওয়ার্কস্পেস একজন গ্রাহকের এবং নিজস্ব ঠিকানায় চলে। এই তালিকা শুধু দেখার জন্য।',
        caption: 'প্ল্যাটফর্মের ওয়ার্কস্পেসগুলো',
        columns: {
            name: 'ওয়ার্কস্পেস',
            address: 'ঠিকানা',
            status: 'অবস্থা',
            members: 'সদস্য',
            locale: 'ভাষা',
            currency: 'মুদ্রা',
            timezone: 'টাইম জোন',
            created: 'তৈরির তারিখ',
        },
        // Keyed by TenantStatus.
        status: {
            active: 'সক্রিয়',
            pending: 'অপেক্ষমাণ',
            suspended: 'স্থগিত',
        },
        search: 'নাম বা ঠিকানা দিয়ে ওয়ার্কস্পেস খুঁজুন',
        searchPlaceholder: 'নাম বা ঠিকানা',
        statusFilter: 'অবস্থা অনুযায়ী ফিল্টার করুন',
        allStatuses: 'সব অবস্থা',
        total: '{count}টি ওয়ার্কস্পেস',
        totalOne: '{count}টি ওয়ার্কস্পেস',
        found: '{count}টি পাওয়া গেছে',
        opensNewTab: 'নতুন ট্যাবে খুলবে',
        empty: {
            title: 'এখনো কোনো ওয়ার্কস্পেস নেই',
            body: 'কোনো ওয়ার্কস্পেস তৈরি হলে ঠিকানা ও অবস্থাসহ সেটি এখানে দেখা যাবে।',
        },
        noMatch: {
            body: 'আরও ছোট নাম বা ঠিকানার একটি অংশ দিয়ে খুঁজে দেখুন, অথবা ফিল্টার মুছুন।',
            clear: 'ফিল্টার মুছুন',
        },
    },
};

export default platform;
