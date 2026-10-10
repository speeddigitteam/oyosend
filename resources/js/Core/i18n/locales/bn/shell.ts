import type { MessageSchema } from '@/Core/i18n';

const shell: MessageSchema['shell'] = {
    breadcrumb: 'ব্রেডক্রাম্ব',
    nav: {
        // Sidebar section headings.
        sections: {
            workspace: 'ওয়ার্কস্পেস',
            apps: 'অ্যাপ',
            manage: 'পরিচালনা',
            platform: 'প্ল্যাটফর্ম',
        },
        // Sidebar entries, keyed by the NavigationItem key the server sends.
        dashboard: 'ড্যাশবোর্ড',
        members: 'সদস্য',
        roles: 'ভূমিকা ও অনুমতি',
        audit: 'অডিট লগ',
        contacts: 'কন্টাক্ট',
        organization: 'শাখা ও বিভাগ',
        workspaceSettings: 'ওয়ার্কস্পেস সেটিংস',
        billing: 'বিলিং',
        tenants: 'ওয়ার্কস্পেস',
        plans: 'প্ল্যান',
        invoices: 'ইনভয়েস',
        platformAudit: 'অডিট লগ',
        platformSettings: 'সেটিংস',
        openMenu: 'মেনু খুলুন',
        closeMenu: 'মেনু বন্ধ করুন',
        primary: 'প্রধান মেনু',
    },
    workspace: {
        switch: 'ওয়ার্কস্পেস বদলান',
        current: 'বর্তমান ওয়ার্কস্পেস',
        yours: 'আপনার ওয়ার্কস্পেস',
        only: 'এটিই আপনার একমাত্র ওয়ার্কস্পেস',
    },
    account: {
        menu: 'অ্যাকাউন্ট মেনু',
        profile: 'প্রোফাইল',
        security: 'নিরাপত্তা',
        signOut: 'সাইন আউট করুন',
        owner: 'মালিক',
    },
    palette: {
        open: 'সরাসরি যান…',
        placeholder: 'পৃষ্ঠা বা অ্যাকশনের নাম লিখুন',
        hint: 'পৃষ্ঠা ও অ্যাকশন খুঁজুন',
        empty: '“{query}”-এর সঙ্গে কোনো পৃষ্ঠা বা অ্যাকশন মিলছে না',
        groups: {
            navigate: 'পৃষ্ঠায় যান',
            actions: 'অ্যাকশন',
            workspaces: 'ওয়ার্কস্পেস বদলান',
        },
        // Each hint follows its key cap: "↑ ↓ ওঠানামা করতে", "↵ খুলতে", "Esc বন্ধ করতে".
        navigateHint: 'ওঠানামা করতে',
        selectHint: 'খুলতে',
        closeHint: 'বন্ধ করতে',
    },
    platform: {
        badge: 'সুপার অ্যাডমিন',
        queues: 'কিউ',
    },
};

export default shell;
