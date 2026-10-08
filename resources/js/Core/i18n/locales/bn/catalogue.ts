import type { MessageSchema } from '@/Core/i18n';

/**
 * Labels for things the server names by key: permissions (registered by
 * modules), permission groups, modules and audit events.
 *
 * The dotted keys are identifiers and stay exactly as in English; only the
 * values are translated.
 *
 * Conventions: permission labels are verbal nouns ("সদস্য দেখা"), their
 * descriptions say what the holder can do ("… পারবেন।"), and audit events
 * are written from the actor's side in the honorific past ("সাইন ইন
 * করেছেন"), or in the passive where the English is ("সদস্য যোগ করা হয়েছে").
 */
const catalogue: MessageSchema['catalogue'] = {
    modules: {
        core: 'ওয়ার্কস্পেস',
    },
    permissionGroups: {
        members: 'সদস্য',
        roles: 'ভূমিকা',
        audit: 'অডিট লগ',
        settings: 'ওয়ার্কস্পেস সেটিংস',
    },
    permissions: {
        'core.members.view': {
            label: 'সদস্য দেখা',
            description: 'ওয়ার্কস্পেসে কারা আছেন এবং কার কী ভূমিকা, তা দেখতে পারবেন।',
        },
        'core.members.invite': {
            label: 'সদস্যদের আমন্ত্রণ জানানো',
            description: 'আমন্ত্রণ পাঠাতে ও প্রত্যাহার করতে পারবেন।',
        },
        'core.members.manage': {
            label: 'সদস্য পরিচালনা',
            description: 'সদস্যদের ভূমিকা পরিবর্তন করতে, তাঁদের স্থগিত করতে ও সরিয়ে দিতে পারবেন।',
        },
        'core.roles.view': {
            label: 'ভূমিকা দেখা',
            description: 'ভূমিকাগুলো এবং কোন ভূমিকায় কী করা যায়, তা দেখতে পারবেন।',
        },
        'core.roles.manage': {
            label: 'ভূমিকা পরিচালনা',
            description: 'ভূমিকা তৈরি করতে ও সেগুলোর অনুমতি পরিবর্তন করতে পারবেন।',
        },
        'core.audit.view': {
            label: 'অডিট লগ দেখা',
            description: 'কে, কখন, কী করেছেন তা দেখতে পারবেন।',
        },
        'core.settings.manage': {
            label: 'ওয়ার্কস্পেস সেটিংস পরিচালনা',
            description: 'ওয়ার্কস্পেসের প্রোফাইল, ভাষা, টাইম জোন ও মুদ্রা পরিবর্তন করতে পারবেন।',
        },
    },
    events: {
        'auth.login': 'সাইন ইন করেছেন',
        'auth.logout': 'সাইন আউট করেছেন',
        'auth.login_failed': 'সাইন ইনের ব্যর্থ চেষ্টা',
        'auth.password_reset': 'পাসওয়ার্ড রিসেট করেছেন',
        'auth.password_changed': 'পাসওয়ার্ড পরিবর্তন করেছেন',
        'auth.two_factor_enabled': 'দুই-ধাপ যাচাইকরণ চালু করেছেন',
        'auth.two_factor_disabled': 'দুই-ধাপ যাচাইকরণ বন্ধ করেছেন',
        'auth.recovery_codes_regenerated': 'নতুন রিকভারি কোড তৈরি করেছেন',
        'member.invited': 'একজন সদস্যকে আমন্ত্রণ জানিয়েছেন',
        'member.invitation_revoked': 'একটি আমন্ত্রণ প্রত্যাহার করেছেন',
        'member.joined': 'ওয়ার্কস্পেসে যোগ দিয়েছেন',
        'member.role_changed': 'একজন সদস্যের ভূমিকা পরিবর্তন করেছেন',
        'tenant_membership.created': 'সদস্য যোগ করা হয়েছে',
        'tenant_membership.updated': 'সদস্য আপডেট করা হয়েছে',
        'tenant_membership.deleted': 'সদস্য সরিয়ে দেওয়া হয়েছে',
        'role.created': 'একটি ভূমিকা তৈরি করেছেন',
        'role.updated': 'একটি ভূমিকা আপডেট করেছেন',
        'role.deleted': 'একটি ভূমিকা মুছে ফেলেছেন',
        'role.permissions_changed': 'একটি ভূমিকার অনুমতি পরিবর্তন করেছেন',
        'platform.auth.login': 'অ্যাডমিন প্যানেলে সাইন ইন করেছেন',
        'platform.auth.logout': 'অ্যাডমিন প্যানেল থেকে সাইন আউট করেছেন',
        'platform.auth.login_failed': 'অ্যাডমিন প্যানেলে সাইন ইনের ব্যর্থ চেষ্টা',
        'platform.auth.two_factor_enabled': 'দুই-ধাপ যাচাইকরণ সেট আপ করেছেন',
        'platform.admin.created': 'একটি অ্যাডমিন অ্যাকাউন্ট তৈরি করেছেন',
    },
    eventFamilies: {
        auth: 'সাইন ইন ও নিরাপত্তা',
        member: 'সদস্য',
        tenant_membership: 'সদস্যপদ',
        role: 'ভূমিকা',
        platform: 'প্ল্যাটফর্ম',
    },
};

export default catalogue;
