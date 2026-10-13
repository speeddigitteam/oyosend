import type { MessageSchema } from '@/Core/i18n';

const notes: MessageSchema['notes'] = {
    eyebrow: 'নোট',
    title: 'নোট',
    description: 'সংক্ষিপ্ত নোট লিখে রাখুন এবং গুরুত্বপূর্ণগুলো পিন করুন।',
    search: 'নোট খুঁজুন',
    count: {
        one: '{count}টি নোট',
        other: '{count}টি নোট',
    },
    empty: {
        title: 'এখনো কোনো নোট নেই',
        body: 'যা গুরুত্বপূর্ণ তা মনে রাখতে প্রথম নোটটি যোগ করুন।',
        viewOnly: 'আপনার টিমের যোগ করা নোট এখানে দেখা যাবে।',
    },
    composer: {
        placeholder: 'একটি নোট লিখুন…',
        title: 'শিরোনাম',
        body: 'একটি নোট লিখুন…',
        add: 'যোগ করুন',
        close: 'বন্ধ করুন',
    },
    sections: {
        pinned: 'পিন করা',
        others: 'অন্যান্য',
    },
    colors: {
        label: 'পটভূমির রঙ',
        default: 'ডিফল্ট',
        brand: 'সবুজ',
        accent: 'কোরাল',
        ok: 'টিল',
        warn: 'অ্যাম্বার',
        danger: 'লাল',
        info: 'নীল',
    },
    card: {
        pin: 'নোট পিন করুন',
        unpin: 'পিন সরান',
        delete: 'নোট মুছে ফেলুন',
    },
    field: {
        title: 'শিরোনাম',
        body: 'নোট',
        contact: 'কন্টাক্ট',
        company: 'প্রতিষ্ঠান',
        owner: 'দায়িত্বে',
        notSet: 'নির্ধারিত নয়',
    },
    edit: {
        heading: 'নোট সম্পাদনা করুন',
        hint: 'একটি নোট আলাদাভাবে থাকতে পারে অথবা কোনো কন্টাক্ট বা প্রতিষ্ঠানের সাথে যুক্ত থাকতে পারে।',
    },
    remove: {
        heading: 'নোট মুছে ফেলুন',
        body: 'এই নোটটি স্থায়ীভাবে মুছে ফেলা হবে। এটি আর ফেরানো যাবে না।',
    },
    common: {
        save: 'সংরক্ষণ করুন',
        cancel: 'বাতিল করুন',
        clear: 'খোঁজ মুছুন',
        unassigned: 'দায়িত্বহীন',
        viewOnly: 'আপনি নোট দেখতে পারেন কিন্তু পরিবর্তন করতে পারবেন না।',
        noMatches: 'কোনো নোট মেলেনি',
        noMatchesBody: 'অন্য কিছু দিয়ে খুঁজুন।',
    },
};

export default notes;
