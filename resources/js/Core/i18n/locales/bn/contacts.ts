import type { MessageSchema } from '@/Core/i18n';

/**
 * Messages for the contact directory: the people and the companies.
 *
 * A contact is counted as a person ("{count} জন"), a company as a thing
 * ("{count}টি"). Bengali has no separate plural form, so `one` and `other`
 * read the same; both keys stay because the code picks between them.
 */
const contacts: MessageSchema['contacts'] = {
    // The switch between the two lists.
    tabs: {
        label: 'কন্টাক্ট ও প্রতিষ্ঠান',
        people: 'ব্যক্তি',
        companies: 'প্রতিষ্ঠান',
    },
    // One label per field, shared by the columns, the forms and the details of both lists.
    fields: {
        name: 'নাম',
        firstName: 'নামের প্রথম অংশ',
        lastName: 'নামের শেষ অংশ',
        email: 'ইমেইল',
        phone: 'ফোন',
        jobTitle: 'পদবি',
        company: 'প্রতিষ্ঠান',
        website: 'ওয়েবসাইট',
        industry: 'ব্যবসার খাত',
        taxId: 'ট্যাক্স আইডি',
        address: 'ঠিকানা',
        city: 'শহর',
        country: 'দেশ',
        location: 'অবস্থান',
        notes: 'নোট',
        owner: 'দায়িত্বে',
        people: 'কন্টাক্ট',
        added: 'যোগ করার তারিখ',
    },
    // What both lists say and offer.
    list: {
        clearSearch: 'অনুসন্ধান মুছুন',
        clearFilters: 'ফিল্টার মুছুন',
        found: {
            one: '{count}টি মিলেছে',
            other: '{count}টি মিলেছে',
        },
        actions: 'অ্যাকশন',
        menu: '{name}-এর জন্য অ্যাকশন',
        view: 'বিস্তারিত দেখুন',
        noOwner: 'কেউ নন',
        notRecorded: 'উল্লেখ নেই',
        opensNewTab: 'নতুন ট্যাবে খুলবে',
        noMatch: {
            title: '“{query}”-এর সঙ্গে কিছুই মিলছে না',
            body: 'বানান ঠিক আছে কি না দেখুন, অথবা ইমেইল ঠিকানা বা ফোন নম্বর দিয়ে খুঁজে দেখুন।',
        },
    },
    form: {
        noCountry: 'নির্ধারিত নয়',
        notesHint: 'এই রেকর্ডটি যাঁরা দেখতে পারেন, তাঁরা সবাই এর নোট পড়তে পারবেন।',
        ownerHint: 'কিছু ভূমিকার পরিসর শুধু নিজের, অথবা নিজের বিভাগ বা শাখার রেকর্ড পর্যন্ত। কোন রেকর্ড তার মধ্যে পড়বে, তা ঠিক হয় কে দায়িত্বে আছেন তা দিয়ে।',
    },
    people: {
        title: 'কন্টাক্ট',
        description: '{workspace} ওয়ার্কস্পেস যাঁদের সঙ্গে কাজ করে: গ্রাহক, সরবরাহকারী ও অংশীদার।',
        caption: 'এই ওয়ার্কস্পেসের কন্টাক্ট',
        add: 'কন্টাক্ট যোগ করুন',
        search: 'নাম, ইমেইল বা ফোন দিয়ে খুঁজুন',
        count: {
            one: '{count} জন কন্টাক্ট',
            other: '{count} জন কন্টাক্ট',
        },
        showCompany: '{company} প্রতিষ্ঠানের কন্টাক্টদের দেখুন',
        clearCompany: 'সব প্রতিষ্ঠানের কন্টাক্ট দেখুন',
        details: 'কন্টাক্টের বিস্তারিত',
        access: {
            viewOnly: 'আপনি এখানকার কন্টাক্টদের দেখতে পারবেন। কন্টাক্ট যোগ বা পরিবর্তন করা আপনার ভূমিকার মধ্যে পড়ে না।',
            partial: 'আপনি কন্টাক্ট যোগ করতে পারবেন, আর যেগুলো পরিচালনার অনুমতি আপনার আছে সেগুলো পরিবর্তন করতে পারবেন। বাকিগুলো শুধু দেখা যাবে।',
        },
        empty: {
            title: 'এখনো কোনো কন্টাক্ট নেই',
            body: 'যাঁদের সঙ্গে আপনি কাজ করেন তাঁদের যোগ করুন। প্রত্যেককে একটি প্রতিষ্ঠানের সঙ্গে যুক্ত করা যাবে।',
            bodyViewOnly: 'যে কন্টাক্টদের দেখার অনুমতি আপনার আছে, তাঁদের এখানে দেখা যাবে।',
            companyTitle: '{company} প্রতিষ্ঠানে কোনো কন্টাক্ট নেই',
            companyBody: 'এই প্রতিষ্ঠানের সঙ্গে যুক্ত করা কন্টাক্টদের এখানে দেখা যাবে।',
            showAll: 'সব কন্টাক্ট দেখুন',
        },
        form: {
            addTitle: 'একটি কন্টাক্ট যোগ করুন',
            editTitle: '{name}-এর তথ্য সম্পাদনা করুন',
            description: 'শুধু নামের প্রথম অংশ দেওয়া আবশ্যক।',
            noCompany: 'কোনো প্রতিষ্ঠান নয়',
            noCompanies: 'বেছে নেওয়ার মতো কোনো প্রতিষ্ঠান এখনো নেই। “প্রতিষ্ঠান” তালিকা থেকে যোগ করুন।',
        },
        delete: {
            title: '{name}-কে মুছে ফেলবেন?',
            body: '{name} কন্টাক্টটি {workspace} ওয়ার্কস্পেসের সবার জন্য তালিকা থেকে মুছে যাবে। এটি আর ফিরিয়ে আনা যাবে না।',
            confirm: 'কন্টাক্ট মুছে ফেলুন',
        },
        merge: {
            menu: 'ডুপ্লিকেট খুঁজুন / মার্জ করুন',
            title: '{name}-এর সম্ভাব্য ডুপ্লিকেট',
            description: 'কন্টাক্টের ইমেইল ঠিকানা বা ফোন নম্বর দিয়ে মিল খোঁজা হয়।',
            loading: 'সম্ভাব্য ডুপ্লিকেট খোঁজা হচ্ছে…',
            loadError: 'সম্ভাব্য ডুপ্লিকেট লোড করা যায়নি।',
            submitError: 'কন্টাক্ট দুটি মার্জ করা যায়নি।',
            tryAgain: 'আবার চেষ্টা করুন',
            emptyTitle: 'কোনো ডুপ্লিকেট পাওয়া যায়নি',
            emptyBody: 'অন্য কোনো কন্টাক্টের একই ইমেইল ঠিকানা বা ফোন নম্বর নেই।',
            matchedBy: 'মিলেছে',
            reasons: {
                email: 'ইমেইল',
                phone: 'ফোন',
            },
            ownedBy: 'দায়িত্বে {name}',
            review: 'মার্জ পর্যালোচনা করুন',
            noPermission: 'শুধু দেখা যাবে',
            chooseTitle: 'যে তথ্য রাখা হবে তা বেছে নিন',
            chooseDescription: '{name} মূল কন্টাক্ট হিসেবে থাকবে।',
            currentContact: 'মূল কন্টাক্ট',
            duplicateContact: 'সম্ভাব্য ডুপ্লিকেট',
            fieldChoice: '{field}-এর যে মান রাখা হবে',
            keepCurrent: 'বর্তমানটি রাখুন',
            useDuplicate: 'ডুপ্লিকেটটি নিন',
            noDifferences: 'তুলনা করা তথ্যগুলো ইতিমধ্যে একই।',
            otherFields: 'এখানে দেখানো হয়নি এমন তথ্য মূল কন্টাক্টে অপরিবর্তিত থাকবে।',
            back: 'পেছনে',
            continue: 'পর্যালোচনা ও নিশ্চিত করুন',
            confirmTitle: 'কন্টাক্ট মার্জ নিশ্চিত করুন',
            confirmDescription: 'ডুপ্লিকেটটি সরানোর আগে ফলাফল দেখে নিন।',
            destructiveTitle: '{name} সরানো হবে',
            destructiveBody: 'সংযুক্ত রেকর্ড সরানোর পর ডুপ্লিকেট কন্টাক্টটি সফট-ডিলিট হবে। এখান থেকে এটি আর ফেরানো যাবে না।',
            referencesMove: 'সংযুক্ত CRM রেকর্ড ও কার্যক্রম {name}-এ চলে যাবে।',
            confirm: 'মার্জ করে ডুপ্লিকেট সরান',
        },
    },
    companies: {
        title: 'প্রতিষ্ঠান',
        description: '{workspace} ওয়ার্কস্পেস যেসব প্রতিষ্ঠানের সঙ্গে কাজ করে, এবং সেগুলোতে যাঁরা কাজ করেন।',
        caption: 'এই ওয়ার্কস্পেসের প্রতিষ্ঠান',
        add: 'প্রতিষ্ঠান যোগ করুন',
        search: 'নাম, ইমেইল বা ফোন দিয়ে খুঁজুন',
        count: {
            one: '{count}টি প্রতিষ্ঠান',
            other: '{count}টি প্রতিষ্ঠান',
        },
        // How many people a company has; `showPeople` takes this as {people}.
        people: {
            one: '{count} জন',
            other: '{count} জন',
        },
        showPeople: '{company} প্রতিষ্ঠানের কন্টাক্টদের দেখুন ({people})',
        details: 'প্রতিষ্ঠানের বিস্তারিত',
        access: {
            viewOnly: 'আপনি এখানকার প্রতিষ্ঠানগুলো দেখতে পারবেন। প্রতিষ্ঠান যোগ বা পরিবর্তন করা আপনার ভূমিকার মধ্যে পড়ে না।',
            partial: 'আপনি প্রতিষ্ঠান যোগ করতে পারবেন, আর যেগুলো পরিচালনার অনুমতি আপনার আছে সেগুলো পরিবর্তন করতে পারবেন। বাকিগুলো শুধু দেখা যাবে।',
        },
        empty: {
            title: 'এখনো কোনো প্রতিষ্ঠান নেই',
            body: 'যেসব প্রতিষ্ঠানের সঙ্গে আপনি কাজ করেন সেগুলো যোগ করুন, তারপর আপনার কন্টাক্টদের সেগুলোর সঙ্গে যুক্ত করুন।',
            bodyViewOnly: 'যে প্রতিষ্ঠানগুলো দেখার অনুমতি আপনার আছে, সেগুলো এখানে দেখা যাবে।',
        },
        form: {
            addTitle: 'একটি প্রতিষ্ঠান যোগ করুন',
            editTitle: '{name}-এর তথ্য সম্পাদনা করুন',
            description: 'শুধু নাম দেওয়া আবশ্যক।',
            websiteHint: 'পুরো ঠিকানা লিখুন, শুরুতে https:// বা http:// সহ',
        },
        delete: {
            title: '{name} মুছে ফেলবেন?',
            body: '{name} প্রতিষ্ঠানটি {workspace} ওয়ার্কস্পেসের সবার জন্য তালিকা থেকে মুছে যাবে। এটি আর ফিরিয়ে আনা যাবে না।',
            peopleStay: 'এর কন্টাক্টরা তালিকায় থেকে যাবেন, তবে কোনো প্রতিষ্ঠানের সঙ্গে যুক্ত থাকবেন না।',
            confirm: 'প্রতিষ্ঠান মুছে ফেলুন',
        },
    },
};

export default contacts;
