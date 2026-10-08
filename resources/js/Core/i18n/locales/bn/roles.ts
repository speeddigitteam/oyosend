import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the `roles` pages.
 *
 * Bengali has no separate plural form, so `one` and `other` differ only where
 * a pronoun does (তাঁকে / তাঁদের, এটি / এগুলো).
 *
 * "Owner" as the name of the system role stays in Latin script, because role
 * names are stored data and shown as stored; the person who owns the
 * workspace is "মালিক".
 */
const roles: MessageSchema['roles'] = {
    index: {
        title: 'ভূমিকা',
        description: 'ভূমিকা হলো নাম দেওয়া একগুচ্ছ অনুমতি। {workspace} ওয়ার্কস্পেসের প্রত্যেকের ঠিক একটি করে ভূমিকা থাকে।',
        create: 'নতুন ভূমিকা',
        columns: {
            role: 'ভূমিকা',
            people: 'সদস্য',
            permissions: 'অনুমতি',
            actions: 'অ্যাকশন',
        },
        system: 'সিস্টেম',
        granted: '{total}-এর মধ্যে {count}',
        grantedAll: 'সব {total}টি',
        edit: 'সম্পাদনা করুন',
        editRole: '{name} সম্পাদনা করুন',
        view: 'দেখুন',
        viewRole: '{name} দেখুন',
        delete: '{name} মুছে ফেলুন',
        systemNote: 'সিস্টেম ভূমিকাগুলো প্রতিটি ওয়ার্কস্পেসে আগে থেকেই থাকে। এগুলোর নাম পরিবর্তন করা যায় না এবং এগুলো মুছে ফেলা যায় না।',
        viewOnly: 'আপনি এখানে ভূমিকাগুলো দেখতে পারবেন, কিন্তু পরিবর্তন করতে পারবেন না।',
        empty: 'এখনো কোনো ভূমিকা নেই',
    },
    delete: {
        title: '{name} মুছে ফেলবেন?',
        body: 'এই ভূমিকায় কেউ নেই, তাই কেউ অ্যাক্সেস হারাবেন না। মুছে ফেলা ভূমিকা আর ফিরিয়ে আনা যায় না।',
        confirm: 'ভূমিকা মুছে ফেলুন',
        inUseTitle: '{name} এখনো ব্যবহৃত হচ্ছে',
        inUse: {
            one: '{count} জন এই ভূমিকায় আছেন। সদস্য পৃষ্ঠায় গিয়ে তাঁকে অন্য ভূমিকা দিন, তারপর এটি মুছে ফেলুন।',
            other: '{count} জন এই ভূমিকায় আছেন। সদস্য পৃষ্ঠায় গিয়ে তাঁদের অন্য ভূমিকা দিন, তারপর এটি মুছে ফেলুন।',
        },
        goToMembers: 'সদস্য পৃষ্ঠায় যান',
    },
    editor: {
        eyebrow: 'ভূমিকা',
        createTitle: 'নতুন ভূমিকা',
        createDescription: 'ভূমিকাটির একটি নাম দিন, তারপর এই ভূমিকার সদস্যরা কী কী করতে পারবেন তা বেছে নিন।',
        editTitle: '{name} ভূমিকা',
        editDescription: 'সংরক্ষণ করার সঙ্গে সঙ্গেই পরিবর্তনগুলো এই ভূমিকার সবার জন্য কার্যকর হবে।',
        back: 'সব ভূমিকা',
        details: 'বিস্তারিত',
        name: 'নাম',
        description: 'বিবরণ',
        descriptionHint: 'এই ভূমিকা কাদের জন্য, তা নিয়ে এক লাইন।',
        systemName: 'সিস্টেম ভূমিকার নাম পরিবর্তন করা যায় না।',
        owner: {
            title: 'Owner ভূমিকা পরিবর্তন করা যায় না',
            body: 'মালিকের কাছে সব সময় ওয়ার্কস্পেসের প্রতিটি অনুমতি থাকে, পরে যোগ করা অ্যাপের অনুমতিগুলোও। নিচের তালিকাটি শুধু দেখার জন্য।',
        },
        permissions: 'অনুমতি',
        permissionsHelp: 'এই ভূমিকার সদস্যরা যা যা করতে পারবেন, সেগুলোতে টিক দিন। টিক না দেওয়া কাজগুলো তাঁরা করতে পারবেন না।',
        locked: {
            one: 'আপনি শুধু সেই অনুমতিগুলোই দিতে পারবেন যা আপনার নিজের আছে। নিচের {count}টি অনুমতি লক করা: এটি আপনি এই ভূমিকায় যোগ করতেও পারবেন না, সরিয়ে নিতেও পারবেন না।',
            other: 'আপনি শুধু সেই অনুমতিগুলোই দিতে পারবেন যা আপনার নিজের আছে। নিচের {count}টি অনুমতি লক করা: এগুলো আপনি এই ভূমিকায় যোগ করতেও পারবেন না, সরিয়ে নিতেও পারবেন না।',
        },
        lockedItem: 'এই অনুমতি আপনার নেই, তাই এটি পরিবর্তন করতে পারবেন না',
        sensitive: 'সংবেদনশীল',
        granted: 'দেওয়া আছে',
        // Read out after a group name: "সদস্য, সব নির্বাচন করুন".
        selectAll: 'সব নির্বাচন করুন',
        groupCount: '{total}-এর মধ্যে {count}',
        moduleCount: '{total}-এর মধ্যে {count}টি নির্বাচিত',
        emptyCatalogue: 'দেওয়ার মতো কোনো অনুমতি এখনো নেই',
        summary: {
            // Both follow a large figure showing the number selected:
            // "৫ অনুমতি নির্বাচিত, মোট ১২টির মধ্যে" and "১২ অনুমতি, সবগুলোই".
            selected: 'অনুমতি নির্বাচিত, মোট {total}টির মধ্যে',
            ownerAll: 'অনুমতি, সবগুলোই',
            added: '{count}টি যোগ হয়েছে',
            removed: '{count}টি সরানো হয়েছে',
            sensitive: '{count}টি সংবেদনশীল',
            unsaved: 'বিস্তারিত পরিবর্তিত হয়েছে',
        },
        create: 'ভূমিকা তৈরি করুন',
    },
};

export default roles;
