import type { MessageSchema } from '@/Core/i18n';

/**
 * Labels for things the server names by key: permissions (registered by
 * modules), permission groups, modules, plan limits, permission scopes and
 * audit events.
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
        organization: 'শাখা ও বিভাগ',
        contacts: 'কন্টাক্ট ও প্রতিষ্ঠান',
        audit: 'অডিট লগ',
        settings: 'ওয়ার্কস্পেস সেটিংস',
        billing: 'বিলিং',
        apps: 'অ্যাপ',
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
            description: 'সদস্যদের ভূমিকা, শাখা ও বিভাগ পরিবর্তন করতে, তাঁদের স্থগিত করতে ও সরিয়ে দিতে পারবেন।',
        },
        'core.roles.view': {
            label: 'ভূমিকা দেখা',
            description: 'ভূমিকাগুলো এবং কোন ভূমিকায় কী করা যায়, তা দেখতে পারবেন।',
        },
        'core.roles.manage': {
            label: 'ভূমিকা পরিচালনা',
            description: 'ভূমিকা তৈরি করতে ও সেগুলোর অনুমতি পরিবর্তন করতে পারবেন।',
        },
        'core.organization.view': {
            label: 'শাখা ও বিভাগ দেখা',
            description: 'প্রতিষ্ঠানের শাখা ও বিভাগগুলো দেখতে পারবেন।',
        },
        'core.organization.manage': {
            label: 'শাখা ও বিভাগ পরিচালনা',
            description: 'শাখা ও বিভাগ যোগ করতে, পরিবর্তন করতে ও মুছে ফেলতে পারবেন।',
        },
        'core.contacts.view': {
            label: 'কন্টাক্ট দেখা',
            description: 'কন্টাক্ট ও প্রতিষ্ঠানগুলো দেখতে পারবেন।',
        },
        'core.contacts.manage': {
            label: 'কন্টাক্ট যোগ ও সম্পাদনা',
            description: 'কন্টাক্ট ও প্রতিষ্ঠান যোগ করতে এবং সেগুলোর তথ্য পরিবর্তন করতে পারবেন।',
        },
        'core.contacts.delete': {
            label: 'কন্টাক্ট মুছে ফেলা',
            description: 'কন্টাক্ট ও প্রতিষ্ঠান মুছে ফেলতে পারবেন।',
        },
        'core.audit.view': {
            label: 'অডিট লগ দেখা',
            description: 'কে, কখন, কী করেছেন তা দেখতে পারবেন।',
        },
        'core.settings.manage': {
            label: 'ওয়ার্কস্পেস সেটিংস পরিচালনা',
            description: 'প্রতিষ্ঠানের প্রোফাইল, লোগো, ভাষা, টাইম জোন ও মুদ্রা পরিবর্তন করতে পারবেন।',
        },
        'core.billing.view': {
            label: 'বিলিং দেখা',
            description: 'ওয়ার্কস্পেসের প্ল্যান, ব্যবহার ও ইনভয়েস দেখতে পারবেন।',
        },
        'core.billing.manage': {
            label: 'বিলিং পরিচালনা',
            description: 'প্ল্যান পরিবর্তন বা বাতিল করতে এবং ইনভয়েস পরিশোধ করতে পারবেন।',
        },
        'core.apps.view': {
            label: 'অ্যাপ দেখা',
            description: 'অ্যাপ স্টোর দেখতে এবং ওয়ার্কস্পেসে ইনস্টল করা অ্যাপ দেখতে পারবেন।',
        },
        'core.apps.manage': {
            label: 'অ্যাপ পরিচালনা',
            description: 'অ্যাপ ইনস্টল, সক্রিয়, নিষ্ক্রিয় ও আনইনস্টল করতে পারবেন।',
        },
    },
    // How far a grant reaches, for permissions that can be narrowed.
    scopes: {
        own: { label: 'নিজের', description: 'শুধু যে রেকর্ডগুলোর দায়িত্বে তিনি নিজে আছেন।' },
        department: { label: 'বিভাগ', description: 'নিজের রেকর্ড এবং তাঁর বিভাগের রেকর্ড।' },
        branch: { label: 'শাখা', description: 'নিজের রেকর্ড এবং তাঁর শাখা ও বিভাগের রেকর্ড।' },
        all: { label: 'সব', description: 'ওয়ার্কস্পেসের সব রেকর্ড।' },
    },
    // What a plan can cap.
    limits: {
        'core.members': {
            label: 'সদস্য',
            description: 'ওয়ার্কস্পেসের সদস্যরা, এবং যে আমন্ত্রণগুলো এখনো গ্রহণ করা যায়।',
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
        'branch.created': 'একটি শাখা যোগ করেছেন',
        'branch.updated': 'একটি শাখা আপডেট করেছেন',
        'branch.deleted': 'একটি শাখা মুছে ফেলেছেন',
        'department.created': 'একটি বিভাগ যোগ করেছেন',
        'department.updated': 'একটি বিভাগ আপডেট করেছেন',
        'department.deleted': 'একটি বিভাগ মুছে ফেলেছেন',
        'contact.created': 'একটি কন্টাক্ট যোগ করেছেন',
        'contact.updated': 'একটি কন্টাক্ট আপডেট করেছেন',
        'contact.deleted': 'একটি কন্টাক্ট মুছে ফেলেছেন',
        'company.created': 'একটি প্রতিষ্ঠান যোগ করেছেন',
        'company.updated': 'একটি প্রতিষ্ঠান আপডেট করেছেন',
        'company.deleted': 'একটি প্রতিষ্ঠান মুছে ফেলেছেন',
        'workspace.registered': 'ওয়ার্কস্পেসের জন্য সাইন আপ করেছেন',
        'workspace.created': 'সাপোর্ট টিম ওয়ার্কস্পেসটি তৈরি করেছে',
        'workspace.approved': 'ওয়ার্কস্পেস অনুমোদন করা হয়েছে',
        'workspace.suspended': 'ওয়ার্কস্পেস স্থগিত করা হয়েছে',
        'workspace.reactivated': 'ওয়ার্কস্পেস আবার সক্রিয় করা হয়েছে',
        'workspace.onboarded': 'ওয়ার্কস্পেসের সেটআপ শেষ করেছেন',
        'workspace.settings_updated': 'ওয়ার্কস্পেস সেটিংস পরিবর্তন করেছেন',
        'workspace.logo_updated': 'ওয়ার্কস্পেসের লোগো পরিবর্তন করেছেন',
        'workspace.logo_removed': 'ওয়ার্কস্পেসের লোগো সরিয়ে দিয়েছেন',
        'billing.subscription_started': 'একটি প্ল্যান শুরু করেছেন',
        'billing.plan_changed': 'প্ল্যান পরিবর্তন করেছেন',
        'billing.plan_change_scheduled': 'প্ল্যান পরিবর্তনের সময় ঠিক করেছেন',
        'billing.plan_granted': 'সাপোর্ট টিম প্ল্যান ঠিক করে দিয়েছে',
        'billing.trial_extended': 'ট্রায়ালের মেয়াদ বাড়ানো হয়েছে',
        'billing.subscription_activated': 'প্ল্যান সক্রিয় করা হয়েছে',
        'billing.subscription_past_due': 'পেমেন্টের সময় পেরিয়ে গেছে',
        'billing.subscription_expired': 'সাবস্ক্রিপশনের মেয়াদ শেষ হয়েছে',
        'billing.subscription_canceled': 'সাবস্ক্রিপশন বাতিল করেছেন',
        'billing.subscription_resumed': 'সাবস্ক্রিপশন আবার চালু করেছেন',
        'billing.subscription_ended': 'সাবস্ক্রিপশন শেষ হয়েছে',
        'billing.invoice_issued': 'ইনভয়েস তৈরি করা হয়েছে',
        'billing.invoice_paid': 'ইনভয়েস পরিশোধ করা হয়েছে',
        'billing.invoice_voided': 'ইনভয়েস প্রত্যাহার করা হয়েছে',
        'billing.invoice_withdrawn': 'সাপোর্ট টিম ইনভয়েস প্রত্যাহার করেছে',
        'billing.payment_started': 'পেমেন্ট শুরু করেছেন',
        'billing.payment_received': 'পেমেন্ট পাওয়া গেছে',
        'billing.payment_recorded': 'সাপোর্ট টিম পেমেন্ট রেকর্ড করেছে',
        'billing.payment_failed': 'পেমেন্ট ব্যর্থ হয়েছে',
        'billing.payment_unapplied': 'বন্ধ হয়ে যাওয়া ইনভয়েসের জন্য পেমেন্ট পাওয়া গেছে',
        'app_store.trial_started': 'একটি অ্যাপের ট্রায়াল শুরু করেছেন',
        'app_store.purchase_started': 'একটি অ্যাপ কেনা শুরু করেছেন',
        'app_store.purchase_activated': 'কেনা অ্যাপটি সক্রিয় করেছেন',
        'app_store.app_installed': 'একটি অ্যাপ ইনস্টল করেছেন',
        'app_store.app_activated': 'একটি অ্যাপ সক্রিয় করেছেন',
        'app_store.app_deactivated': 'একটি অ্যাপ নিষ্ক্রিয় করেছেন',
        'app_store.app_uninstalled': 'একটি অ্যাপ আনইনস্টল করেছেন',
        'app_store.app_reinstalled': 'একটি অ্যাপ আবার ইনস্টল করেছেন',
        'app_store.app_upgraded': 'একটি অ্যাপ আপডেট করেছেন',
        'app_store.settings_updated': 'একটি অ্যাপের সেটিংস পরিবর্তন করেছেন',
        'app_store.subscription_canceled': 'একটি অ্যাপের সাবস্ক্রিপশন বাতিল করেছেন',
        'app_store.subscription_resumed': 'একটি অ্যাপের সাবস্ক্রিপশন আবার চালু করেছেন',
        'app_store.subscription_past_due': 'অ্যাপের পেমেন্টের সময় পেরিয়ে গেছে',
        'app_store.subscription_ended': 'অ্যাপের সাবস্ক্রিপশন শেষ হয়েছে',
        'app_store.subscription_expired': 'অ্যাপের সাবস্ক্রিপশনের মেয়াদ শেষ হয়েছে',
        'app_store.invoice_issued': 'অ্যাপের ইনভয়েস তৈরি করা হয়েছে',
        'support.session_started': 'সাপোর্ট টিম এই ওয়ার্কস্পেসে প্রবেশ করেছে',
        'support.session_ended': 'সাপোর্ট টিম এই ওয়ার্কস্পেস থেকে বেরিয়ে গেছে',
        'plan.created': 'একটি প্ল্যান তৈরি করেছেন',
        'plan.updated': 'একটি প্ল্যান আপডেট করেছেন',
        'plan.deleted': 'একটি প্ল্যান মুছে ফেলেছেন',
        'platform.auth.login': 'অ্যাডমিন প্যানেলে সাইন ইন করেছেন',
        'platform.auth.logout': 'অ্যাডমিন প্যানেল থেকে সাইন আউট করেছেন',
        'platform.auth.login_failed': 'অ্যাডমিন প্যানেলে সাইন ইনের ব্যর্থ চেষ্টা',
        'platform.auth.two_factor_enabled': 'দুই-ধাপ যাচাইকরণ সেট আপ করেছেন',
        'platform.admin.created': 'একটি অ্যাডমিন অ্যাকাউন্ট তৈরি করেছেন',
        'platform.workspace.registered': 'একটি ওয়ার্কস্পেস সাইন আপ করেছে',
        'platform.workspace.created': 'একটি ওয়ার্কস্পেস তৈরি করেছেন',
        'platform.workspace.approved': 'একটি ওয়ার্কস্পেস অনুমোদন করেছেন',
        'platform.workspace.suspended': 'একটি ওয়ার্কস্পেস স্থগিত করেছেন',
        'platform.workspace.reactivated': 'একটি ওয়ার্কস্পেস আবার সক্রিয় করেছেন',
        'platform.impersonation.started': 'মালিক হিসেবে একটি ওয়ার্কস্পেসে প্রবেশ করেছেন',
        'platform.impersonation.ended': 'একটি ওয়ার্কস্পেস থেকে বেরিয়ে গেছেন',
        'platform.settings.updated': 'প্ল্যাটফর্ম সেটিংস পরিবর্তন করেছেন',
    },
    eventFamilies: {
        auth: 'সাইন ইন ও নিরাপত্তা',
        member: 'সদস্য',
        tenant_membership: 'সদস্যপদ',
        role: 'ভূমিকা',
        branch: 'শাখা',
        department: 'বিভাগ',
        contact: 'কন্টাক্ট',
        company: 'প্রতিষ্ঠান',
        workspace: 'ওয়ার্কস্পেস',
        billing: 'বিলিং',
        app_store: 'অ্যাপ',
        support: 'সাপোর্ট অ্যাক্সেস',
        plan: 'প্ল্যান',
        platform: 'প্ল্যাটফর্ম',
    },
};

export default catalogue;
