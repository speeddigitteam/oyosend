import type { MessageSchema } from '@/Core/i18n';

const auth: MessageSchema['auth'] = {
    // Left-hand panel of the sign-in screens.
    aside: {
        eyebrow: 'একটিই ওয়ার্কস্পেস',
        headline: 'সবাই এক ঘরে, প্রত্যেকের হাতে নিজের চাবি।',
        body: 'আপনার প্রতিষ্ঠানের জন্য একটি ওয়ার্কস্পেস: এর সদস্যরা, কে কী করতে পারবেন, আর কী করা হয়েছে তার রেকর্ড।',
        points: {
            isolation: 'আপনার ডেটা আপনার ওয়ার্কস্পেসেই থাকে',
            languages: 'English ও বাংলা',
            roles: 'কে কী করতে পারবেন, তা ভূমিকা ঠিক করে',
        },
    },
    login: {
        // Title and subtitle are read together: "সাইন ইন করুন / {workspace} ওয়ার্কস্পেসে".
        title: 'সাইন ইন করুন',
        subtitle: '{workspace} ওয়ার্কস্পেসে',
        email: 'ইমেইল',
        password: 'পাসওয়ার্ড',
        remember: 'এই ডিভাইসে আমাকে সাইন ইন অবস্থায় রাখুন',
        submit: 'সাইন ইন করুন',
        forgot: 'পাসওয়ার্ড ভুলে গেছেন?',
        wrongWorkspace: 'এটি আপনার ওয়ার্কস্পেস নয়?',
        findWorkspace: 'আপনারটি খুঁজে নিন',
    },
    twoFactor: {
        title: 'দুই-ধাপ যাচাইকরণ',
        subtitle: 'আপনার অথেনটিকেটর অ্যাপ থেকে ছয় অঙ্কের কোডটি লিখুন।',
        recoverySubtitle: 'দুই-ধাপ যাচাইকরণ চালু করার সময় যে রিকভারি কোডগুলো সংরক্ষণ করেছিলেন, তার একটি লিখুন।',
        code: 'যাচাইকরণ কোড',
        recoveryCode: 'রিকভারি কোড',
        submit: 'যাচাই করুন',
        useRecovery: 'এর বদলে রিকভারি কোড ব্যবহার করুন',
        useCode: 'এর বদলে অথেনটিকেটর অ্যাপের কোড ব্যবহার করুন',
        recoveryHint: 'প্রতিটি রিকভারি কোড একবারই কাজ করে।',
        back: 'সাইন ইনে ফিরে যান',
    },
    forgot: {
        title: 'পাসওয়ার্ড রিসেট করুন',
        subtitle: 'আপনার ইমেইল লিখুন, নতুন পাসওয়ার্ড বেছে নেওয়ার একটি লিংক আমরা পাঠিয়ে দেব।',
        email: 'ইমেইল',
        submit: 'রিসেট লিংক পাঠান',
        resend: 'আবার পাঠান',
        // Shown after a request. It must not say whether the address was recognised.
        afterSend: 'কয়েক মিনিটেও কিছু পাননি? স্প্যাম ফোল্ডার দেখুন, আর এই ওয়ার্কস্পেসে আপনি এই ঠিকানাটিই ব্যবহার করেন কি না মিলিয়ে নিন।',
        back: 'সাইন ইনে ফিরে যান',
    },
    reset: {
        title: 'নতুন পাসওয়ার্ড বেছে নিন',
        subtitle: 'অন্য সব জায়গা থেকে আপনাকে সাইন আউট করা হবে।',
        email: 'ইমেইল',
        password: 'নতুন পাসওয়ার্ড',
        passwordConfirmation: 'নতুন পাসওয়ার্ড নিশ্চিত করুন',
        submit: 'পাসওয়ার্ড রিসেট করুন',
        requestNew: 'নতুন লিংক চেয়ে নিন',
        back: 'সাইন ইনে ফিরে যান',
    },
    invitation: {
        title: '{workspace} ওয়ার্কস্পেসে যোগ দিন',
        invitedBy: '{inviter} আপনাকে {role} হিসেবে যোগ দেওয়ার আমন্ত্রণ জানিয়েছেন।',
        invited: 'আপনাকে {role} হিসেবে যোগ দেওয়ার আমন্ত্রণ জানানো হয়েছে।',
        emailLabel: 'আমন্ত্রিত ইমেইল',
        expires: 'মেয়াদ শেষ হবে {date}',
        name: 'আপনার নাম',
        password: 'একটি পাসওয়ার্ড বেছে নিন',
        passwordConfirmation: 'পাসওয়ার্ড নিশ্চিত করুন',
        existingPassword: 'আপনার Oyosend পাসওয়ার্ড',
        existingHint: 'এই ইমেইল দিয়ে আপনার আগে থেকেই একটি Oyosend অ্যাকাউন্ট আছে। যোগ দিতে সেই অ্যাকাউন্টের পাসওয়ার্ড লিখুন।',
        confirmHint: 'আপনি {email} দিয়ে সাইন ইন করা আছেন। এই ওয়ার্কস্পেসে যোগ দিতে আমন্ত্রণটি গ্রহণ করুন।',
        accept: 'গ্রহণ করে যোগ দিন',
        createAndJoin: 'অ্যাকাউন্ট তৈরি করে যোগ দিন',
        states: {
            invalid: {
                title: 'এই আমন্ত্রণ লিংকটি সঠিক নয়',
                body: 'ইমেইলে পাওয়া পুরো লিংকটি খুলেছেন কি না দেখে নিন, অথবা নতুন একটি আমন্ত্রণ চেয়ে নিন।',
            },
            expired: {
                title: 'এই আমন্ত্রণের মেয়াদ শেষ হয়ে গেছে',
                body: 'যিনি আপনাকে আমন্ত্রণ জানিয়েছেন, তাঁকে নতুন একটি পাঠাতে বলুন।',
            },
            revoked: {
                title: 'এই আমন্ত্রণটি প্রত্যাহার করা হয়েছে',
                body: 'এখনো অ্যাক্সেস দরকার হলে ওয়ার্কস্পেসের কোনো অ্যাডমিনকে বলুন।',
            },
            accepted: {
                title: 'এই আমন্ত্রণটি আগেই ব্যবহার করা হয়েছে',
                body: 'আপনিই ব্যবহার করে থাকলে এগিয়ে যেতে সাইন ইন করুন।',
            },
            wrong_account: {
                title: 'এই আমন্ত্রণটি অন্য কারও জন্য',
                body: 'আপনি অন্য একটি ইমেইল দিয়ে সাইন ইন করা আছেন। সাইন আউট করে লিংকটি আবার খুলুন।',
            },
        },
        signIn: 'সাইন ইন পৃষ্ঠায় যান',
        switchAccount: 'সাইন আউট করে আবার চেষ্টা করুন',
    },
    // Describes the production rule (see AppServiceProvider); local development accepts shorter passwords.
    // The number is spelt out so it reads the same whichever numerals the person has chosen.
    passwordRules: 'বারো বা তার বেশি অক্ষর ব্যবহার করুন, যার মধ্যে বড় হাতের ও ছোট হাতের অক্ষর এবং একটি সংখ্যা থাকবে।',
    show: 'পাসওয়ার্ড দেখান',
    hide: 'পাসওয়ার্ড লুকান',
};

export default auth;
